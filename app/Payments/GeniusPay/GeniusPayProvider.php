<?php

declare(strict_types=1);

namespace App\Payments\GeniusPay;

use App\Contracts\Payments\NormalizedPaymentStatus;
use App\Contracts\Payments\PaymentConfirmationProvider;
use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\ProviderInitiationRequest;
use App\Contracts\Payments\ProviderInitiationResult;
use App\Contracts\Payments\ProviderPaymentVerificationRequest;
use App\Contracts\Payments\ProviderPaymentVerificationResult;
use App\Contracts\Payments\ProviderWebhookEnvelope;
use App\Services\Payments\PaymentConfirmationException;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Real GeniusPay adapter (Genius Pay gate) — DISABLED by default.
 *
 * Resolved only when `config('payments.driver') === 'geniuspay'` and every required
 * credential is present; otherwise the binding throws
 * {@see Reason::ProviderConfigurationFailure} and NO HTTP request is ever made — the same
 * fail-closed rule CinetPay obeys.
 *
 * Official endpoints, from the public documentation (`pay.genius.ci/docs/api`). Nothing
 * here is invented; anything the documentation does not state is refused rather than
 * guessed, exactly as `POWERPAY_SETUP.md` requires:
 *
 *   initialisation  POST {base_url}/payments
 *   verification    GET  {base_url}/payments/{reference}
 *
 * Authentication is by two headers, `X-API-Key` (public) and `X-API-Secret` (secret).
 * Both are read from configuration only and never appear in an exception or a log.
 *
 * ⚠️ SANDBOX FIRST. `GENIUSPAY_ENVIRONMENT` never SELECTS an environment — the credentials
 * themselves do that. It is a cross-check: declared `sandbox` with a `_live_` key, or the
 * reverse, refuses before any request. Without it, "I am testing against sandbox" is a
 * belief rather than a fact, and the way that belief fails is a real charge on a real card.
 * Switching to live stays a `.env` decision, taken by a human, once sandbox is proven end
 * to end.
 *
 * @phpstan-type GeniusPayConfig array{
 *     environment:string, api_key:string, api_secret:string, webhook_secret:string,
 *     base_url:string, timeout?:int, connect_timeout?:int, require_https?:bool,
 *     webhook_tolerance_seconds?:int
 * }
 */
final class GeniusPayProvider implements PaymentConfirmationProvider, PaymentProvider
{
    private const NAME = 'geniuspay';

    /**
     * The only currency this adapter will send.
     *
     * ⚠️ NOT a default — a REFUSAL boundary. GeniusPay converts non-XOF amounts
     * automatically, which would silently make the provider's captured amount differ from
     * `orders.total_minor` and divert every confirmation to manual review. An ambiguity
     * worth refusing rather than handling: the day a second currency is genuinely sold,
     * this constant is the one place that must be revisited deliberately.
     */
    private const SUPPORTED_CURRENCY = 'XOF';

    /**
     * A payment reference is interpolated into a URL path, so its charset — not its exact
     * shape — is what must be constrained. `MTX-XXXXXXXXXX` is the documented form; the
     * pattern stays deliberately wider on length so a provider-side format change does not
     * break verification, while `/`, `.` sequences, spaces and control characters can never
     * rewrite the request path.
     */
    private const REFERENCE_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->assertConfigured();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function initiate(ProviderInitiationRequest $request): ProviderInitiationResult
    {
        // Refuse BEFORE any network call: a converted amount is undetectable afterwards.
        if ($request->currency !== self::SUPPORTED_CURRENCY) {
            throw PaymentConfirmationException::of(
                Reason::ProviderConfigurationFailure,
                'The payment provider is not configured.',
            );
        }

        $data = $this->send('POST', $this->endpoint('/payments'), [
            // Integer minor units, never a float — the amount is already an int here.
            'amount' => $request->amountMinor,
            // Sent EXPLICITLY on every request; the provider default is never relied upon.
            'currency' => $request->currency,
        ]);

        $inner = $this->body($data);

        $reference = $this->text($inner['reference'] ?? null);
        $url = $this->text($inner['checkout_url'] ?? null);
        if ($url === '') {
            $url = $this->text($inner['payment_url'] ?? null);
        }

        if (preg_match(self::REFERENCE_PATTERN, $reference) !== 1 || ! $this->isHttpsUrl($url)) {
            throw PaymentConfirmationException::of(
                Reason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }

        return new ProviderInitiationResult(
            // The reference is the ONLY handle GeniusPay accepts on the verification
            // endpoint, so it is what gets persisted as the provider payment reference.
            providerPaymentReference: $reference,
            providerStatus: ($status = $this->text($inner['status'] ?? null)) !== '' ? $status : null,
            clientInstructions: ['payment_url' => $url],
        );
    }

    public function verifyPayment(ProviderPaymentVerificationRequest $request): ProviderPaymentVerificationResult
    {
        $reference = trim($request->providerTransactionId);

        if (preg_match(self::REFERENCE_PATTERN, $reference) !== 1) {
            throw PaymentConfirmationException::of(
                Reason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }

        $data = $this->send('GET', $this->endpoint('/payments/'.rawurlencode($reference)));
        $inner = $this->body($data);

        $status = $this->text($inner['status'] ?? null);
        $currency = $this->text($inner['currency'] ?? null);

        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw PaymentConfirmationException::of(
                Reason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }

        $returnedReference = $this->text($inner['reference'] ?? null);
        $method = $this->text($inner['payment_method'] ?? null);

        return new ProviderPaymentVerificationResult(
            provider: self::NAME,
            normalizedStatus: $this->mapStatus($status),
            amountMinor: $this->parseIntegerAmount($inner['amount'] ?? null),
            currency: $currency,
            providerPaymentReference: $returnedReference !== '' ? $returnedReference : $reference,
            providerStatus: $status !== '' ? $status : null,
            paymentMethod: $method !== '' ? $method : null,
        );
    }

    /**
     * Constant-time HMAC over `X-Webhook-Timestamp . "." . RAW BODY`.
     *
     * ⚠️ THE RAW BYTES, never a re-encoded body. `json_decode` then `json_encode` reorders
     * keys, drops insignificant whitespace and rewrites escapes — the digest would differ
     * from what the provider signed, so every legitimate webhook would be rejected and, far
     * worse, any "fix" for that would end up trusting a body nobody actually verified.
     */
    public function verifyWebhookSignature(ProviderWebhookEnvelope $webhook): bool
    {
        $signature = $webhook->header('x-webhook-signature');
        $timestamp = $webhook->header('x-webhook-timestamp');
        $rawBody = $webhook->rawBody;

        if ($signature === null || trim($signature) === '') {
            return false;
        }

        if ($timestamp === null || trim($timestamp) === '' || $rawBody === null) {
            return false;
        }

        if (! $this->isFreshTimestamp(trim($timestamp))) {
            return false;
        }

        // The timestamp is signed VERBATIM: its formatting is the provider's business, and
        // reformatting it here would break the digest.
        $expected = hash_hmac('sha256', trim($timestamp).'.'.$rawBody, $this->str('webhook_secret'));

        return hash_equals($expected, trim($signature));
    }

    /**
     * Replay window. A signature stays valid for ever without it: an attacker who captures
     * one legitimate notification could otherwise re-post it years later.
     *
     * The tolerance is symmetric — a provider clock a few seconds AHEAD of ours is ordinary
     * NTP drift, not an attack, and refusing it would drop real notifications.
     */
    private function isFreshTimestamp(string $timestamp): bool
    {
        $tolerance = (int) ($this->config['webhook_tolerance_seconds'] ?? 300);
        if ($tolerance <= 0) {
            return false;
        }

        $sentAt = $this->parseTimestamp($timestamp);
        if ($sentAt === null) {
            return false;
        }

        return abs(CarbonImmutable::now()->getTimestamp() - $sentAt) <= $tolerance;
    }

    /**
     * Unix seconds, or an ISO-8601 instant as a fallback. Anything else is refused rather
     * than coerced: a timestamp that cannot be read is a timestamp that cannot be bounded.
     */
    private function parseTimestamp(string $timestamp): ?int
    {
        if (preg_match('/\A[0-9]{1,12}\z/', $timestamp) === 1) {
            return (int) $timestamp;
        }

        try {
            return CarbonImmutable::parse($timestamp)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Isolated vendor→normalized status mapping.
     *
     * `expired` maps to `Cancelled`, not `Unknown`: it is terminal and unpaid, and `Unknown`
     * would leave the payment pending for ever.
     *
     * ⚠️ `refunded` maps to `Unknown` DELIBERATELY. It cannot be `Succeeded` — that would
     * confirm money that has been given back — and it cannot be `Failed` — the money did
     * change hands first. `Unknown` is fail-closed here, and it is why a refund NEVER
     * travels through `verifyPayment()`: it has its own intake path.
     */
    private function mapStatus(string $status): NormalizedPaymentStatus
    {
        return match (strtolower(trim($status))) {
            'completed' => NormalizedPaymentStatus::Succeeded,
            'processing' => NormalizedPaymentStatus::Processing,
            'pending' => NormalizedPaymentStatus::Pending,
            'failed' => NormalizedPaymentStatus::Failed,
            'cancelled', 'expired' => NormalizedPaymentStatus::Cancelled,
            default => NormalizedPaymentStatus::Unknown,
        };
    }

    /**
     * Strict integer-in-minor-units parse: a digit string or an integer only.
     * No float, no scientific notation, no separator, no overflow.
     */
    private function parseIntegerAmount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[0-9]{1,18}\z/', $value) === 1) {
            return (int) $value;
        }

        throw PaymentConfirmationException::of(
            Reason::ProviderProtocolFailure,
            'The payment provider returned an invalid amount.',
        );
    }

    /**
     * The payment object, whether the provider nests it under `data` or returns it flat.
     *
     * Defensive READING, not an invented contract: both shapes are refused when neither
     * yields the fields the caller then validates, so a silent mis-read is impossible.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function body(array $data): array
    {
        $inner = $data['data'] ?? null;

        return is_array($inner) ? $inner : $data;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<mixed>
     */
    private function send(string $method, string $url, ?array $payload = null): array
    {
        try {
            $request = Http::asJson()
                ->acceptJson()
                ->withHeaders([
                    'X-API-Key' => $this->str('api_key'),
                    'X-API-Secret' => $this->str('api_secret'),
                ])
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
                ->timeout((int) ($this->config['timeout'] ?? 15));

            $response = $method === 'POST'
                ? $request->post($url, $payload ?? [])
                : $request->get($url);
        } catch (ConnectionException) {
            throw PaymentConfirmationException::of(
                Reason::ProviderUnavailable,
                'The payment provider is currently unavailable.',
            );
        } catch (Throwable) {
            // Any other transport-level failure is sanitised; no detail leaks.
            throw PaymentConfirmationException::of(
                Reason::ProviderUnavailable,
                'The payment provider is currently unavailable.',
            );
        }

        if ($response->serverError()) {
            throw PaymentConfirmationException::of(
                Reason::ProviderUnavailable,
                'The payment provider is currently unavailable.',
            );
        }

        if ($response->clientError()) {
            throw PaymentConfirmationException::of(
                Reason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw PaymentConfirmationException::of(
                Reason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }

        return $data;
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->str('base_url'), '/').$path;
    }

    /**
     * The credential markers that contradict each declared environment.
     *
     * Only a CONTRADICTION refuses. A credential carrying neither marker — a test double,
     * or a future GeniusPay key format — passes: this guards against one catastrophic
     * mistake, it is not a format validator that would break the day a prefix is renamed.
     */
    private const FORBIDDEN_CREDENTIAL_MARKER = [
        'sandbox' => '_live_',
        'live' => '_sandbox_',
    ];

    private function assertConfigured(): void
    {
        foreach (['environment', 'api_key', 'api_secret', 'webhook_secret', 'base_url'] as $key) {
            $value = $this->config[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw PaymentConfirmationException::of(
                    Reason::ProviderConfigurationFailure,
                    'The payment provider is not configured.',
                );
            }
        }

        if (($this->config['require_https'] ?? true) === true && ! $this->isHttpsUrl($this->str('base_url'))) {
            throw PaymentConfirmationException::of(
                Reason::ProviderConfigurationFailure,
                'The payment provider is not configured.',
            );
        }

        $this->assertCredentialsMatchEnvironment();
    }

    private function assertCredentialsMatchEnvironment(): void
    {
        $environment = strtolower(trim($this->str('environment')));

        // An unrecognised value is refused, never treated as sandbox: a typo must not
        // silently disable the very guard that exists to catch a mistake.
        $forbidden = self::FORBIDDEN_CREDENTIAL_MARKER[$environment] ?? null;
        if ($forbidden === null) {
            throw PaymentConfirmationException::of(
                Reason::ProviderConfigurationFailure,
                'The payment provider is not configured.',
            );
        }

        foreach (['api_key', 'api_secret', 'webhook_secret'] as $key) {
            // Compared locally and never echoed: the exception names no credential, and no
            // value reaches a message or a log.
            if (str_contains(strtolower($this->str($key)), $forbidden)) {
                throw PaymentConfirmationException::of(
                    Reason::ProviderConfigurationFailure,
                    'The payment provider is not configured.',
                );
            }
        }
    }

    private function str(string $key): string
    {
        return (string) $this->config[$key];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && str_starts_with(strtolower($url), 'https://');
    }
}
