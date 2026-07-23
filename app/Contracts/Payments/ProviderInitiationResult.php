<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

/**
 * What a provider returns from an initiation (P3-D3, D-033).
 *
 * `clientInstructions` are ephemeral (a USSD string, a redirect URL, a QR
 * payload): they live only in memory on the way back to the caller and are
 * NEVER persisted, logged, or placed in an exception. P3-D3 does not write
 * `provider_metadata` at all.
 */
final readonly class ProviderInitiationResult
{
    /**
     * @param  array<string, mixed>|null  $clientInstructions
     */
    public function __construct(
        public string $providerPaymentReference,
        public ?string $providerStatus = null,
        public ?string $providerMethod = null,
        public ?array $clientInstructions = null,
    ) {}
}
