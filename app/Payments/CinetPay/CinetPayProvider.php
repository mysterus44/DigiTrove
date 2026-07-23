<?php

declare(strict_types=1);

namespace App\Payments\CinetPay;

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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Real CinetPay adapter (P3-D4, D-034) — DISABLED by default.
 *
 * It is resolved only when `config('payments.driver') === 'cinetpay'` and every
 * required credential is present; otherwise the binding throws
 * {@see Reason::ProviderConfigurationFailure} and NO HTTP request is ever made.
 *
 * Official endpoints (overridable only by configuration):
 *   initialisation  POST https://api-checkout.cinetpay.com/v2/payment
 *   verification    POST https://api-checkout.cinetpay.com/v2/payment/check
 *
 * This adapter is the ONLY place that knows CinetPay's JSON shape, its status
 * labels and the `x-token` HMAC field order. Secrets are read from config only
 * and never appear in an exception or a log.
 *
 * @phpstan-type CinetPayConfig array{
 *     api_key:string, site_id:string, secret_key:string,
 *     init_url:string, check_url:string, channels?:string, lang?:string,
 *     timeout?:int, connect_timeout?:int, require_https?:bool,
 *     notify_url?:?string, return_url?:?string
 * }
 */
final class CinetPayProvider implements PaymentConfirmationProvider, PaymentProvider
{
    private const NAME = 'cinetpay';

    /** Exact field order used to rebuild the CinetPay `x-token` HMAC string. */
    private const HMAC_FIELDS = [
        'cpm_site_id',
        'cpm_trans_id',
        'cpm_trans_date',
        'cpm_amount',
        'cpm_currency',
        'signature',
        'payment_method',
        'cel_phone_num',
        'cpm_phone_prefixe',
        'cpm_language',
        'cpm_version',
        'cpm_payment_config',
        'cpm_page_action',
        'cpm_custom',
        'cpm_designation',
        'cpm_error_message',
    ];

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
        $payload = [
            'apikey' => $this->str('api_key'),
            'site_id' => $this->str('site_id'),
            'transaction_id' => $request->paymentPublicId,
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
            // Deliberately free of PII / order contents.
            'description' => 'Order '.substr($request->orderPublicId, 0, 8),
            'customer_email' => $request->customerEmail,
            'channels' => $this->config['channels'] ?? 'ALL',
            'lang' => $this->config['lang'] ?? 'fr',
        ];

        foreach (['notify_url', 'return_url'] as $optional) {
            $value = $this->config[$optional] ?? null;
            if (is_string($value) && $value !== '') {
                $payload[$optional] = $value;
            }
        }

        $data = $this->post($this->str('init_url'), $payload);

        if ((string) ($data['code'] ?? '') !== '201') {
            throw PaymentConfirmationException::of(
                Reason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }

        $inner = is_array($data['data'] ?? null) ? $data['data'] : [];
        $token = is_string($inner['payment_token'] ?? null) ? trim($inner['payment_token']) : '';
        $url = is_string($inner['payment_url'] ?? null) ? trim($inner['payment_url']) : '';

        if ($token === '' || ! $this->isHttpsUrl($url)) {
            throw PaymentConfirmationException::of(
                Reason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }

        return new ProviderInitiationResult(
            providerPaymentReference: $token,
            clientInstructions: ['payment_url' => $url],
        );
    }

    public function verifyPayment(ProviderPaymentVerificationRequest $request): ProviderPaymentVerificationResult
    {
        $data = $this->post($this->str('check_url'), [
            'transaction_id' => $request->providerTransactionId,
            'site_id' => $this->str('site_id'),
            'apikey' => $this->str('api_key'),
        ]);

        $inner = $data['data'] ?? null;
        if (! is_array($inner)) {
            throw PaymentConfirmationException::of(
                Reason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }

        $status = is_string($inner['status'] ?? null) ? $inner['status'] : '';
        $currency = is_string($inner['currency'] ?? null) ? $inner['currency'] : '';

        if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw PaymentConfirmationException::of(
                Reason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }

        $reference = is_string($inner['operator_id'] ?? null) ? trim($inner['operator_id']) : '';
        $method = is_string($inner['payment_method'] ?? null) ? trim($inner['payment_method']) : '';

        return new ProviderPaymentVerificationResult(
            provider: self::NAME,
            normalizedStatus: $this->mapStatus($status),
            amountMinor: $this->parseIntegerAmount($inner['amount'] ?? null),
            currency: $currency,
            providerPaymentReference: $reference !== '' ? $reference : null,
            providerStatus: $status !== '' ? $status : null,
            paymentMethod: $method !== '' ? $method : null,
        );
    }

    public function verifyWebhookSignature(ProviderWebhookEnvelope $webhook): bool
    {
        $token = $webhook->header('x-token');
        if ($token === null || trim($token) === '') {
            return false;
        }

        $concatenated = '';
        foreach (self::HMAC_FIELDS as $field) {
            $concatenated .= (string) ($webhook->param($field) ?? '');
        }

        $expected = hash_hmac('sha256', $concatenated, $this->str('secret_key'));

        return hash_equals($expected, $token);
    }

    /** Isolated vendor→normalized status mapping (D-034). */
    private function mapStatus(string $status): NormalizedPaymentStatus
    {
        return match (strtoupper(trim($status))) {
            'ACCEPTED' => NormalizedPaymentStatus::Succeeded,
            'WAITING_FOR_CUSTOMER', 'PENDING' => NormalizedPaymentStatus::Processing,
            'REFUSED' => NormalizedPaymentStatus::Failed,
            'CANCELLED' => NormalizedPaymentStatus::Cancelled,
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
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    private function post(string $url, array $payload): array
    {
        try {
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout((int) ($this->config['connect_timeout'] ?? 4))
                ->timeout((int) ($this->config['timeout'] ?? 8))
                ->post($url, $payload);
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

    private function assertConfigured(): void
    {
        foreach (['api_key', 'site_id', 'secret_key', 'init_url', 'check_url'] as $key) {
            $value = $this->config[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw PaymentConfirmationException::of(
                    Reason::ProviderConfigurationFailure,
                    'The payment provider is not configured.',
                );
            }
        }

        if (($this->config['require_https'] ?? true) === true) {
            foreach (['init_url', 'check_url'] as $key) {
                if (! $this->isHttpsUrl((string) $this->config[$key])) {
                    throw PaymentConfirmationException::of(
                        Reason::ProviderConfigurationFailure,
                        'The payment provider is not configured.',
                    );
                }
            }
        }
    }

    private function str(string $key): string
    {
        return (string) $this->config[$key];
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && str_starts_with(strtolower($url), 'https://');
    }
}
