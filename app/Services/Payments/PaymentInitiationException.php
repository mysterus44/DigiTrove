<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/**
 * A payment initiation could not proceed (P3-D3, D-033).
 *
 * Messages are generic: no SQLSTATE, no constraint name, no SQL, no internal
 * id, no provider response body, no raw idempotency key and no digest. The
 * future HTTP layer maps `reason` to a response.
 */
final class PaymentInitiationException extends RuntimeException
{
    private function __construct(
        public readonly PaymentInitiationRefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(PaymentInitiationRefusalReason $reason, string $message): self
    {
        return new self($reason, $message);
    }

    public static function orderUnavailable(): self
    {
        // Same message whether the order does not exist or belongs to someone
        // else: never confirm the existence of a stranger's order.
        return new self(
            PaymentInitiationRefusalReason::OrderUnavailable,
            'This order is not available for payment.',
        );
    }
}
