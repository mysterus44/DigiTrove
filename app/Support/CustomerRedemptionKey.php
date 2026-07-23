<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;

/**
 * Derives the stable, non-reversible customer identity stored on a
 * `coupon_redemptions` row (P3-D4, D-034).
 *
 * The key is a SHA-256 over the order's most stable actor reference — a
 * registered user, else a visitor, else the normalised e-mail — matching the
 * `coupon_redemptions_customer_key_hash_format_check` (`^[0-9a-f]{64}$`). The
 * raw identity never lands in the table; only its digest does.
 */
final class CustomerRedemptionKey
{
    /** Bump only if the derivation below changes. */
    public const VERSION = 1;

    public static function forOrder(Order $order): string
    {
        if ($order->user_id !== null) {
            $material = 'user:'.$order->user_id;
        } elseif ($order->visitor_id !== null) {
            $material = 'visitor:'.$order->visitor_id;
        } else {
            $material = 'email:'.mb_strtolower(trim((string) $order->customer_email));
        }

        return hash('sha256', $material);
    }
}
