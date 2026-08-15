<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The guest cart lifetime, read once and validated before any write.
 *
 * Mirrors `CheckoutConfig`: a malformed value fails BEFORE a cart row is created, never
 * after. A cart persisted with a nonsense `expires_at` would be worse than a refusal —
 * it would be invisible until the day someone wondered why nothing ever expired.
 */
final class CartConfig
{
    public static function ttlDays(): int
    {
        $value = config('cart.ttl_days');

        if (is_int($value)) {
            $days = $value;
        } elseif (is_string($value) && preg_match('/\A\d+\z/', $value) === 1) {
            $days = (int) $value;
        } else {
            throw new RuntimeException('The cart lifetime is invalid.');
        }

        if ($days < 1) {
            throw new RuntimeException('The cart lifetime is invalid.');
        }

        return $days;
    }
}
