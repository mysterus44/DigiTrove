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
     */
    public function __construct(
        array $headers,
        public array $params,
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
