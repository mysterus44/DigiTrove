<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;

/**
 * The result the initiation service returns to its caller (P3-D3, D-033).
 *
 * `clientInstructions` are ephemeral and in-memory only: they are never
 * persisted (P3-D3 does not write `provider_metadata`), logged or serialised
 * into a job or an event.
 */
final readonly class InitiatedPayment
{
    /**
     * @param  array<string, mixed>|null  $clientInstructions
     */
    public function __construct(
        public string $paymentPublicId,
        public PaymentStatus $status,
        public int $attemptNumber,
        public int $amountMinor,
        public string $currency,
        public ?string $providerPaymentReference,
        public ?array $clientInstructions = null,
    ) {}
}
