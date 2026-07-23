<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

use Carbon\CarbonImmutable;

/**
 * The normalized result of a provider counter-verification (P3-D4, D-034).
 *
 * This is the ONLY payment truth the confirmation service consumes. Money is an
 * integer in minor units; the status is already normalized inside the adapter.
 * It never carries an API key, an HMAC secret, a card/Mobile-Money token, a raw
 * provider body or an Eloquent model.
 */
final readonly class ProviderPaymentVerificationResult
{
    public function __construct(
        public string $provider,
        public NormalizedPaymentStatus $normalizedStatus,
        public int $amountMinor,
        public string $currency,
        public ?string $providerPaymentReference = null,
        public ?string $providerStatus = null,
        public ?string $paymentMethod = null,
        public ?CarbonImmutable $paidAt = null,
        public ?string $externalEventId = null,
    ) {}
}
