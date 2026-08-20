<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

/**
 * A validated, bounded snapshot of an inbound provider webhook (P3-D4, D-034).
 *
 * The HTTP layer has already enforced length/type limits on every field before
 * this DTO exists. It carries only strings: no Eloquent model, no secret, no
 * raw request object. The `x-token`/signature header is data to be *checked*,
 * never trusted, and the body is never authoritative even when its HMAC is
 * valid.
 *
 * @phpstan-type StringMap array<string, string>
 */
final readonly class ProviderWebhookEnvelope
{
    /** @var array<string, string> lower-cased header map */
    private array $normalizedHeaders;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $params
     * @param  ?string  $rawBody  the EXACT bytes of the request body, when the provider signs
     *                            them rather than a field concatenation. Added for GeniusPay,
     *                            whose HMAC covers `timestamp . "." . raw JSON`: re-encoding a
     *                            decoded body would change key order and whitespace, so the
     *                            signature must be checked against what actually arrived.
     *                            CinetPay signs form fields and leaves this `null`, so its
     *                            behaviour is unchanged.
     */
    public function __construct(
        array $headers,
        public array $params,
        public ?string $rawBody = null,
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        $this->normalizedHeaders = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->normalizedHeaders[strtolower($name)] ?? null;
    }

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }
}
