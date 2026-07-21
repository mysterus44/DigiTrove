<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use RuntimeException;

/**
 * A checkout could not produce an Order.
 *
 * Messages are deliberately generic: no SQL, no constraint name, no raw
 * idempotency key, no internal id and nothing about another owner. The future
 * HTTP layer maps `reason` to a response.
 */
final class CheckoutException extends RuntimeException
{
    private function __construct(
        public readonly CheckoutRefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(CheckoutRefusalReason $reason, string $message): self
    {
        return new self($reason, $message);
    }

    public static function cartUnavailable(): self
    {
        // Same message whether the cart does not exist or belongs to someone
        // else: never confirm the existence of a stranger's cart.
        return new self(CheckoutRefusalReason::CartUnavailable, 'This cart is not available for checkout.');
    }
}
