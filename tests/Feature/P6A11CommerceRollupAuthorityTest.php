<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

function createTestContact(): int
{
    $email = 'test_'.strtolower(Str::random(10)).'@example.com';
    DB::connection('pgsql_migration')->insert("INSERT INTO crm_contacts (public_id, email, user_id, origin, status, created_at, updated_at) VALUES (?, ?, NULL, 'guest_order', 'active', NOW(), NOW())", [Str::uuid(), $email]);

    return (int) DB::connection('pgsql_migration')->getPdo()->lastInsertId();
}

function createTestOrder(string $status = 'paid', int $totalMinor = 1000, string $currency = 'XOF', bool $setPaidAt = true): int
{
    return DB::connection('pgsql_migration')->transaction(function () use ($status, $totalMinor, $currency, $setPaidAt) {
        $orderNumber = 'DGT-2026-'.substr(str_shuffle('ABCDEFGHJKMNPQRSTVWXYZ23456789'), 0, 10);
        $email = 'buyer_'.strtolower(Str::random(10)).'@example.com';
        $paidAt = $setPaidAt ? 'NOW()' : 'NULL';
        DB::connection('pgsql_migration')->insert("INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email, currency, total_minor, status, subtotal_minor, discount_minor, tax_minor, placed_at, expires_at, paid_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), NOW() + interval '1 day', $paidAt, NOW(), NOW())",
            [Str::uuid(), $orderNumber, hash('sha256', Str::random(32)), $email, $currency, $totalMinor, $status, $totalMinor]
        );
        $orderId = (int) DB::connection('pgsql_migration')->getPdo()->lastInsertId();

        DB::connection('pgsql_migration')->insert("INSERT INTO order_items (order_id, product_id, purchased_product_id, product_name_snapshot, product_slug_snapshot, product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor, line_total_minor, currency, created_at, updated_at) VALUES (?, NULL, 1, 'Test Product', 'test-product', 'ebook', ?, 1, ?, 0, ?, ?, NOW(), NOW())",
            [$orderId, $totalMinor, $totalMinor, $totalMinor, $currency]
        );

        return $orderId;
    });
}

function attributeOrderToContact(int $orderId, int $contactId): void
{
    DB::connection('pgsql_migration')->insert("INSERT INTO crm_order_attributions (order_id, contact_id, source, attributed_at) VALUES (?, ?, 'existing_contact_snapshot', NOW())", [$orderId, $contactId]);
}

function createPayment(int $orderId, int $amountMinor, string $currency, string $status = 'succeeded'): int
{
    DB::connection('pgsql_migration')->insert("INSERT INTO payments (public_id, order_id, attempt_number, provider, idempotency_key_hash, amount_minor, currency, status, created_at, updated_at) VALUES (?, ?, 1, 'stripe', ?, ?, ?, ?, NOW(), NOW())",
        [Str::uuid(), $orderId, hash('sha256', Str::random(32)), $amountMinor, $currency, $status]
    );

    return (int) DB::connection('pgsql_migration')->getPdo()->lastInsertId();
}

function createRefund(int $paymentId, int $amountMinor, string $currency, string $status = 'succeeded'): int
{
    $succeededAt = $status === 'succeeded' ? 'NOW()' : 'NULL';
    $failedAt = $status === 'failed' ? 'NOW()' : 'NULL';
    $cancelledAt = $status === 'cancelled' ? 'NOW()' : 'NULL';
    DB::connection('pgsql_migration')->insert("INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor, currency, status, succeeded_at, failed_at, cancelled_at, created_at, updated_at) VALUES (?, ?, 'stripe', ?, ?, ?, ?, $succeededAt, $failedAt, $cancelledAt, NOW(), NOW())",
        [Str::uuid(), $paymentId, hash('sha256', Str::random(32)), $amountMinor, $currency, $status]
    );

    return (int) DB::connection('pgsql_migration')->getPdo()->lastInsertId();
}

function callRefresh(int $contactId, string $currency): object
{
    return DB::connection('pgsql_migration')->selectOne('SELECT * FROM refresh_crm_contact_commerce_rollup(?, ?)', [$contactId, $currency]);
}

// ── Calculation tests ─────────────────────────────────────────────────────────

it('calculates a basic paid order and refund accurately', function () {
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('partially_refunded', 5000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        $paymentId = createPayment($orderId, 5000, 'XOF');
        createRefund($paymentId, 2000, 'XOF');
    });

    $result = callRefresh($contactId, 'XOF');
    expect($result->exists_after_refresh)->toBeTrue()
        ->and($result->acquired_orders_count)->toBe(1)
        ->and($result->gross_revenue_minor)->toBe(5000)
        ->and($result->refunded_amount_minor)->toBe(2000)
        ->and($result->net_revenue_minor)->toBe(3000)
        ->and($result->last_refunded_at)->not->toBeNull();
});

it('includes a free order without payments and increments the count but zero amounts', function () {
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('paid', 0, 'USD');
        attributeOrderToContact($orderId, $contactId);
    });

    $result = callRefresh($contactId, 'USD');
    expect($result->exists_after_refresh)->toBeTrue()
        ->and($result->acquired_orders_count)->toBe(1)
        ->and($result->gross_revenue_minor)->toBe(0)
        ->and($result->refunded_amount_minor)->toBe(0)
        ->and($result->net_revenue_minor)->toBe(0)
        ->and($result->last_refunded_at)->toBeNull();
});

it('ignores pending orders, payment reviews, cancelled or unassigned', function () {
    $contactId = createTestContact();

    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        // Valid paid order
        $validOrder = createTestOrder('paid', 1000, 'EUR');
        attributeOrderToContact($validOrder, $contactId);
        createPayment($validOrder, 1000, 'EUR');

        // Ignored states
        $pending = createTestOrder('pending', 1000, 'EUR', false);
        attributeOrderToContact($pending, $contactId);

        $cancelled = createTestOrder('cancelled', 1000, 'EUR', false);
        attributeOrderToContact($cancelled, $contactId);

        $paymentReview = createTestOrder('payment_review', 1000, 'EUR', false);
        attributeOrderToContact($paymentReview, $contactId);
        createPayment($paymentReview, 1000, 'EUR', 'requires_review');
    });

    $result = callRefresh($contactId, 'EUR');
    expect($result->exists_after_refresh)->toBeTrue()
        ->and($result->acquired_orders_count)->toBe(1)
        ->and($result->gross_revenue_minor)->toBe(1000);
});

it('deletes the projection when a stale rollup exists but no eligible orders remain', function () {
    // Create a contact, insert a stale rollup row directly (migrator connection
    // is the table owner and can INSERT), then verify that refresh deletes it.
    // No trigger bypass, no Order mutation — purely via direct owner INSERT.
    $contactId = createTestContact();

    // Insert a stale projection directly via the migrator (owner) connection.
    DB::connection('pgsql_migration')->insert(
        "INSERT INTO crm_contact_commerce_rollups (contact_id, currency, acquired_orders_count, gross_revenue_minor, refunded_amount_minor, first_acquired_at, last_acquired_at, last_refunded_at, calculation_version, refreshed_at) VALUES (?, 'XOF', 1, 1000, 0, NOW(), NOW(), NULL, 1, NOW())",
        [$contactId]
    );

    // Verify the stale row exists
    $before = DB::connection('pgsql_migration')->selectOne(
        "SELECT COUNT(*) AS c FROM crm_contact_commerce_rollups WHERE contact_id = ? AND currency = 'XOF'",
        [$contactId]
    );
    expect((int) $before->c)->toBe(1);

    // Refresh: no eligible orders for this contact/currency → row deleted
    $result = callRefresh($contactId, 'XOF');
    expect($result->exists_after_refresh)->toBeFalse()
        ->and($result->acquired_orders_count)->toBeNull();

    // Verify the stale row is gone
    $after = DB::connection('pgsql_migration')->selectOne(
        "SELECT COUNT(*) AS c FROM crm_contact_commerce_rollups WHERE contact_id = ? AND currency = 'XOF'",
        [$contactId]
    );
    expect((int) $after->c)->toBe(0);
});

it('does not multiply gross revenue by the number of refunds', function () {
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('partially_refunded', 10000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        $paymentId = createPayment($orderId, 10000, 'XOF');
        createRefund($paymentId, 3000, 'XOF');
        createRefund($paymentId, 2000, 'XOF');
    });

    $result = callRefresh($contactId, 'XOF');
    // gross must be 10000, not 10000 * 2 (number of refunds)
    expect($result->gross_revenue_minor)->toBe(10000)
        ->and($result->refunded_amount_minor)->toBe(5000)
        ->and($result->net_revenue_minor)->toBe(5000);
});

it('aggregates multiple orders correctly', function () {
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $order1 = createTestOrder('paid', 1000, 'XOF');
        attributeOrderToContact($order1, $contactId);
        createPayment($order1, 1000, 'XOF');

        $order2 = createTestOrder('paid', 2000, 'XOF');
        attributeOrderToContact($order2, $contactId);
        createPayment($order2, 2000, 'XOF');

        $order3 = createTestOrder('refunded', 3000, 'XOF');
        attributeOrderToContact($order3, $contactId);
        $p3 = createPayment($order3, 3000, 'XOF');
        createRefund($p3, 3000, 'XOF');
    });

    $result = callRefresh($contactId, 'XOF');
    expect($result->acquired_orders_count)->toBe(3)
        ->and($result->gross_revenue_minor)->toBe(6000)
        ->and($result->refunded_amount_minor)->toBe(3000)
        ->and($result->net_revenue_minor)->toBe(3000);
});

it('ignores pending and failed refunds', function () {
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('partially_refunded', 5000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        $paymentId = createPayment($orderId, 5000, 'XOF');
        createRefund($paymentId, 2000, 'XOF', 'succeeded');
        createRefund($paymentId, 1000, 'XOF', 'pending');
        createRefund($paymentId, 500, 'XOF', 'failed');
    });

    $result = callRefresh($contactId, 'XOF');
    expect($result->refunded_amount_minor)->toBe(2000)
        ->and($result->net_revenue_minor)->toBe(3000);
});

it('returns error on overflow', function () {
    $contactId = createTestContact();
    // 9223372036854775807 is max BIGINT
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('paid', 9000000000000000000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        createPayment($orderId, 9000000000000000000, 'XOF');

        $orderId2 = createTestOrder('paid', 9000000000000000000, 'XOF');
        attributeOrderToContact($orderId2, $contactId);
        createPayment($orderId2, 9000000000000000000, 'XOF');
    });

    $this->expectException(QueryException::class);
    $this->expectExceptionMessageMatches('/gross revenue overflow/i');
    callRefresh($contactId, 'XOF');
});

it('preserves the existing projection when overflow occurs', function () {
    $contactId = createTestContact();

    // First, create a valid projection
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('paid', 1000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        createPayment($orderId, 1000, 'XOF');
    });
    $initial = callRefresh($contactId, 'XOF');
    expect($initial->exists_after_refresh)->toBeTrue();

    // Now add orders that will cause overflow
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('paid', 9000000000000000000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        createPayment($orderId, 9000000000000000000, 'XOF');

        $orderId2 = createTestOrder('paid', 9000000000000000000, 'XOF');
        attributeOrderToContact($orderId2, $contactId);
        createPayment($orderId2, 9000000000000000000, 'XOF');
    });

    try {
        callRefresh($contactId, 'XOF');
    } catch (QueryException) {
        // Expected
    }

    // The original projection must still exist unchanged
    $preserved = DB::connection('pgsql_migration')->selectOne(
        "SELECT acquired_orders_count, gross_revenue_minor FROM crm_contact_commerce_rollups WHERE contact_id = ? AND currency = 'XOF'",
        [$contactId]
    );
    expect($preserved)->not->toBeNull()
        ->and((int) $preserved->acquired_orders_count)->toBe(1)
        ->and((int) $preserved->gross_revenue_minor)->toBe(1000);
});

it('preserves history for anonymized contacts', function () {
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('paid', 1000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        createPayment($orderId, 1000, 'XOF');
    });

    // Anonymize
    DB::connection('pgsql_migration')->update("UPDATE crm_contacts SET email = NULL, status = 'anonymized', anonymized_at = NOW() WHERE id = ?", [$contactId]);

    $result = callRefresh($contactId, 'XOF');
    expect($result->exists_after_refresh)->toBeTrue()
        ->and($result->acquired_orders_count)->toBe(1);
});

it('keeps currencies isolated', function () {
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderXof = createTestOrder('paid', 1000, 'XOF');
        attributeOrderToContact($orderXof, $contactId);
        createPayment($orderXof, 1000, 'XOF');

        $orderUsd = createTestOrder('paid', 50, 'USD');
        attributeOrderToContact($orderUsd, $contactId);
        createPayment($orderUsd, 50, 'USD');
    });

    $resultXof = callRefresh($contactId, 'XOF');
    $resultUsd = callRefresh($contactId, 'USD');

    expect($resultXof->gross_revenue_minor)->toBe(1000)
        ->and($resultUsd->gross_revenue_minor)->toBe(50);
});

// ── Idempotency / Lifecycle tests ─────────────────────────────────────────────

it('produces identical results on replay', function () {
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('paid', 2500, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        createPayment($orderId, 2500, 'XOF');
    });

    $r1 = callRefresh($contactId, 'XOF');
    $r2 = callRefresh($contactId, 'XOF');

    expect($r1->acquired_orders_count)->toBe($r2->acquired_orders_count)
        ->and($r1->gross_revenue_minor)->toBe($r2->gross_revenue_minor)
        ->and($r1->refunded_amount_minor)->toBe($r2->refunded_amount_minor)
        ->and($r1->net_revenue_minor)->toBe($r2->net_revenue_minor);
});

it('reconstructs after a new order is added', function () {
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('paid', 1000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        createPayment($orderId, 1000, 'XOF');
    });

    $r1 = callRefresh($contactId, 'XOF');
    expect($r1->acquired_orders_count)->toBe(1);

    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId2 = createTestOrder('paid', 2000, 'XOF');
        attributeOrderToContact($orderId2, $contactId);
        createPayment($orderId2, 2000, 'XOF');
    });

    $r2 = callRefresh($contactId, 'XOF');
    expect($r2->acquired_orders_count)->toBe(2)
        ->and($r2->gross_revenue_minor)->toBe(3000);
});

it('reconstructs after a new refund is added', function () {
    $contactId = createTestContact();
    $paymentId = 0;
    $orderId = 0;
    DB::connection('pgsql_migration')->transaction(function () use ($contactId, &$paymentId, &$orderId) {
        $orderId = createTestOrder('paid', 5000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        $paymentId = createPayment($orderId, 5000, 'XOF');
    });

    $r1 = callRefresh($contactId, 'XOF');
    expect($r1->refunded_amount_minor)->toBe(0);

    // Add a refund — must update Order status to partially_refunded first (Commerce invariant)
    DB::connection('pgsql_migration')->transaction(function () use ($orderId, $paymentId) {
        DB::connection('pgsql_migration')->update("UPDATE orders SET status = 'partially_refunded', updated_at = NOW() WHERE id = ?", [$orderId]);
        createRefund($paymentId, 1500, 'XOF');
    });

    $r2 = callRefresh($contactId, 'XOF');
    expect($r2->refunded_amount_minor)->toBe(1500)
        ->and($r2->net_revenue_minor)->toBe(3500)
        ->and($r2->last_refunded_at)->not->toBeNull();
});

// ── Input validation tests ────────────────────────────────────────────────────

it('rejects invalid inputs', function () {
    $this->expectException(QueryException::class);
    callRefresh(0, 'XOF');
});

it('rejects lowercase currency', function () {
    $contactId = createTestContact();
    $this->expectException(QueryException::class);
    callRefresh($contactId, 'xof');
});

it('rejects non existent contact', function () {
    $this->expectException(QueryException::class);
    callRefresh(999999, 'XOF');
});

// ── last_refunded_at is factual, no CHECK against first_acquired_at ───────────

it('stores last_refunded_at as factual without enforcing temporal order against paid_at', function () {
    // The Commerce schema does not guarantee refunds.succeeded_at >= orders.paid_at.
    // The rollup stores last_refunded_at as a factual observation, not an invariant.
    $contactId = createTestContact();
    DB::connection('pgsql_migration')->transaction(function () use ($contactId) {
        $orderId = createTestOrder('partially_refunded', 5000, 'XOF');
        attributeOrderToContact($orderId, $contactId);
        $paymentId = createPayment($orderId, 5000, 'XOF');
        createRefund($paymentId, 1000, 'XOF');
    });

    $result = callRefresh($contactId, 'XOF');
    // last_refunded_at is stored factually from MAX(refunds.succeeded_at)
    expect($result->last_refunded_at)->not->toBeNull()
        ->and($result->first_acquired_at)->not->toBeNull();
});
