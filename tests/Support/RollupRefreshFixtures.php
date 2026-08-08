<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Commerce-valid fixtures for the P6-A1.2 durable rollup-refresh pipeline. All writes
 * go through the migrator/owner connection and respect the Commerce invariants: a
 * total>0 acquired order carries exactly one succeeded payment, and a succeeded refund
 * is committed together with the matching order status (deferred consistency trigger).
 */
final class RollupRefreshFixtures
{
    public static function owner(): Connection
    {
        return DB::connection('pgsql_migration');
    }

    public static function contact(): int
    {
        self::owner()->insert(
            "INSERT INTO crm_contacts (public_id, email, user_id, origin, status, created_at, updated_at) VALUES (?, ?, NULL, 'guest_order', 'active', NOW(), NOW())",
            [Str::uuid(), 'rr_'.strtolower(Str::random(10)).'@example.com'],
        );

        return (int) self::owner()->getPdo()->lastInsertId();
    }

    /** @return array{order:int,payment:?int} */
    public static function paidOrder(int $totalMinor, string $currency = 'XOF', string $status = 'paid'): array
    {
        return self::owner()->transaction(function () use ($totalMinor, $currency, $status): array {
            $orderNumber = 'DGT-2026-'.substr(str_shuffle('ABCDEFGHJKMNPQRSTVWXYZ23456789'), 0, 10);
            self::owner()->insert(
                "INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email, currency, total_minor, status, subtotal_minor, discount_minor, tax_minor, placed_at, expires_at, paid_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), NOW() + interval '1 day', NOW(), NOW(), NOW())",
                [Str::uuid(), $orderNumber, hash('sha256', Str::random(32)), 'buyer_'.strtolower(Str::random(10)).'@example.com', $currency, $totalMinor, $status, $totalMinor],
            );
            $orderId = (int) self::owner()->getPdo()->lastInsertId();

            self::owner()->insert(
                "INSERT INTO order_items (order_id, product_id, purchased_product_id, product_name_snapshot, product_slug_snapshot, product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor, line_total_minor, currency, created_at, updated_at) VALUES (?, NULL, 1, 'P', 'p', 'ebook', ?, 1, ?, 0, ?, ?, NOW(), NOW())",
                [$orderId, $totalMinor, $totalMinor, $totalMinor, $currency],
            );

            $paymentId = null;
            if ($totalMinor > 0) {
                self::owner()->insert(
                    "INSERT INTO payments (public_id, order_id, attempt_number, provider, idempotency_key_hash, amount_minor, currency, status, created_at, updated_at) VALUES (?, ?, 1, 'stripe', ?, ?, ?, 'succeeded', NOW(), NOW())",
                    [Str::uuid(), $orderId, hash('sha256', Str::random(32)), $totalMinor, $currency],
                );
                $paymentId = (int) self::owner()->getPdo()->lastInsertId();
            }

            return ['order' => $orderId, 'payment' => $paymentId];
        });
    }

    public static function attribute(int $orderId, int $contactId): void
    {
        self::owner()->insert(
            "INSERT INTO crm_order_attributions (order_id, contact_id, source, attributed_at) VALUES (?, ?, 'existing_contact_snapshot', NOW())",
            [$orderId, $contactId],
        );
    }

    public static function insertRefund(int $paymentId, int $amountMinor, string $status = 'succeeded'): void
    {
        $succeededAt = $status === 'succeeded' ? 'NOW()' : 'NULL';
        $failedAt = $status === 'failed' ? 'NOW()' : 'NULL';
        $cancelledAt = $status === 'cancelled' ? 'NOW()' : 'NULL';
        self::owner()->insert(
            "INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor, currency, status, succeeded_at, failed_at, cancelled_at, created_at, updated_at) SELECT ?, ?, 'stripe', ?, ?, p.currency, ?, $succeededAt, $failedAt, $cancelledAt, NOW(), NOW() FROM payments p WHERE p.id = ?",
            [Str::uuid(), $paymentId, hash('sha256', Str::random(32)), $amountMinor, $status, $paymentId],
        );
    }

    public static function succeedRefund(int $orderId, int $paymentId, int $amountMinor, int $totalMinor): void
    {
        self::owner()->transaction(function () use ($orderId, $paymentId, $amountMinor, $totalMinor): void {
            self::insertRefund($paymentId, $amountMinor, 'succeeded');
            $status = $amountMinor >= $totalMinor ? 'refunded' : 'partially_refunded';
            self::owner()->update('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $orderId]);
        });
    }

    public static function outbox(int $contactId, string $currency): ?object
    {
        return self::owner()->selectOne(
            'SELECT * FROM crm_commerce_rollup_refresh_outbox WHERE contact_id = ? AND currency = ?',
            [$contactId, $currency],
        );
    }

    public static function outboxCount(int $contactId): int
    {
        return (int) self::owner()->selectOne(
            'SELECT COUNT(*) AS c FROM crm_commerce_rollup_refresh_outbox WHERE contact_id = ?',
            [$contactId],
        )->c;
    }

    public static function process(int $contactId, string $currency): object
    {
        return self::owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_refresh(?, ?)', [$contactId, $currency]);
    }

    public static function markTerminal(int $contactId, string $currency): void
    {
        self::owner()->update(
            "UPDATE crm_commerce_rollup_refresh_outbox SET terminal_at = NOW(), terminal_reason = 'unexpected', updated_at = NOW() WHERE contact_id = ? AND currency = ?",
            [$contactId, $currency],
        );
    }

    public static function rollup(int $contactId, string $currency): ?object
    {
        return self::owner()->selectOne(
            'SELECT * FROM crm_contact_commerce_rollups WHERE contact_id = ? AND currency = ?',
            [$contactId, $currency],
        );
    }
}
