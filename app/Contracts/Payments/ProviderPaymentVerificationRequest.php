<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

/**
 * The server-side request for a provider counter-verification (P3-D4, D-034).
 *
 * It identifies the attempt ONLY by the stable server value the provider was
 * given at initiation: `payment.public_id`. It never carries an email, a phone
 * number, an amount, a raw idempotency key or a digest — the verification is a
 * source-of-truth read, not a client claim.
 */
final readonly class ProviderPaymentVerificationRequest
{
    public function __construct(
        public string $providerTransactionId,
    ) {}
}
