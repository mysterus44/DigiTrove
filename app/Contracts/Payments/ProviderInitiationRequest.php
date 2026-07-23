<?php

declare(strict_types=1);

namespace App\Contracts\Payments;

/**
 * The server-side snapshot handed to a payment provider (P3-D3, D-033).
 *
 * It carries ONLY data the server already committed: nothing the client
 * supplied at initiation, no cart, no raw idempotency key and no digest.
 *
 * `paymentPublicId` is the provider-facing idempotency key — public, non-secret,
 * and stable across every replay of the same attempt.
 */
final readonly class ProviderInitiationRequest
{
    public function __construct(
        public string $paymentPublicId,
        public string $orderPublicId,
        public int $amountMinor,
        public string $currency,
        public string $customerEmail,
    ) {}
}
