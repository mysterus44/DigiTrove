<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A1.2 durable signals + generation-safe processing (single-connection cases).
 * Concurrency across real connections lives in P6A12RollupRefreshConcurrencyTest.
 *
 * Fixtures respect Commerce invariants: a total>0 acquired order carries exactly one
 * succeeded payment, and a succeeded refund is committed together with the matching
 * order status (partially_refunded/refunded) so the deferred consistency trigger holds.
 */
function p6a12Owner(): Connection
{
    return DB::connection('pgsql_migration');
}

function p6a12Contact(): int
{
    p6a12Owner()->insert(
        "INSERT INTO crm_contacts (public_id, email, user_id, origin, status, created_at, updated_at) VALUES (?, ?, NULL, 'guest_order', 'active', NOW(), NOW())",
        [Str::uuid(), 'p6a12_'.strtolower(Str::random(10)).'@example.com'],
    );

    return (int) p6a12Owner()->getPdo()->lastInsertId();
}

/** @return array{order:int,payment:?int} */
function p6a12PaidOrder(int $totalMinor, string $currency = 'XOF', string $status = 'paid'): array
{
    return p6a12Owner()->transaction(function () use ($totalMinor, $currency, $status): array {
        $orderNumber = 'DGT-2026-'.substr(str_shuffle('ABCDEFGHJKMNPQRSTVWXYZ23456789'), 0, 10);
        p6a12Owner()->insert(
            "INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email, currency, total_minor, status, subtotal_minor, discount_minor, tax_minor, placed_at, expires_at, paid_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), NOW() + interval '1 day', NOW(), NOW(), NOW())",
            [Str::uuid(), $orderNumber, hash('sha256', Str::random(32)), 'buyer_'.strtolower(Str::random(10)).'@example.com', $currency, $totalMinor, $status, $totalMinor],
        );
        $orderId = (int) p6a12Owner()->getPdo()->lastInsertId();

        p6a12Owner()->insert(
            "INSERT INTO order_items (order_id, product_id, purchased_product_id, product_name_snapshot, product_slug_snapshot, product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor, line_total_minor, currency, created_at, updated_at) VALUES (?, NULL, 1, 'P', 'p', 'ebook', ?, 1, ?, 0, ?, ?, NOW(), NOW())",
            [$orderId, $totalMinor, $totalMinor, $totalMinor, $currency],
        );

        $paymentId = null;
        if ($totalMinor > 0) {
            p6a12Owner()->insert(
                "INSERT INTO payments (public_id, order_id, attempt_number, provider, idempotency_key_hash, amount_minor, currency, status, created_at, updated_at) VALUES (?, ?, 1, 'stripe', ?, ?, ?, 'succeeded', NOW(), NOW())",
                [Str::uuid(), $orderId, hash('sha256', Str::random(32)), $totalMinor, $currency],
            );
            $paymentId = (int) p6a12Owner()->getPdo()->lastInsertId();
        }

        return ['order' => $orderId, 'payment' => $paymentId];
    });
}

function p6a12Attribute(int $orderId, int $contactId): void
{
    p6a12Owner()->insert(
        "INSERT INTO crm_order_attributions (order_id, contact_id, source, attributed_at) VALUES (?, ?, 'existing_contact_snapshot', NOW())",
        [$orderId, $contactId],
    );
}

function p6a12InsertRefund(int $paymentId, int $amountMinor, string $status = 'succeeded'): void
{
    $succeededAt = $status === 'succeeded' ? 'NOW()' : 'NULL';
    $failedAt = $status === 'failed' ? 'NOW()' : 'NULL';
    $cancelledAt = $status === 'cancelled' ? 'NOW()' : 'NULL';
    p6a12Owner()->insert(
        "INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor, currency, status, succeeded_at, failed_at, cancelled_at, created_at, updated_at) SELECT ?, ?, 'stripe', ?, ?, p.currency, ?, $succeededAt, $failedAt, $cancelledAt, NOW(), NOW() FROM payments p WHERE p.id = ?",
        [Str::uuid(), $paymentId, hash('sha256', Str::random(32)), $amountMinor, $status, $paymentId],
    );
}

/** Insert a succeeded refund and move the order to its consistent refunded status atomically. */
function p6a12SucceedRefund(int $orderId, int $paymentId, int $amountMinor, int $totalMinor): void
{
    p6a12Owner()->transaction(function () use ($orderId, $paymentId, $amountMinor, $totalMinor): void {
        p6a12InsertRefund($paymentId, $amountMinor, 'succeeded');
        $status = $amountMinor >= $totalMinor ? 'refunded' : 'partially_refunded';
        p6a12Owner()->update('UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $orderId]);
    });
}

function p6a12Outbox(int $contactId, string $currency): ?object
{
    return p6a12Owner()->selectOne(
        'SELECT * FROM crm_commerce_rollup_refresh_outbox WHERE contact_id = ? AND currency = ?',
        [$contactId, $currency],
    );
}

function p6a12OutboxCount(int $contactId): int
{
    return (int) p6a12Owner()->selectOne('SELECT COUNT(*) AS c FROM crm_commerce_rollup_refresh_outbox WHERE contact_id = ?', [$contactId])->c;
}

function p6a12Process(int $contactId, string $currency): object
{
    return p6a12Owner()->selectOne('SELECT * FROM process_crm_commerce_rollup_refresh(?, ?)', [$contactId, $currency]);
}

function p6a12Rollup(int $contactId, string $currency): ?object
{
    return p6a12Owner()->selectOne(
        'SELECT * FROM crm_contact_commerce_rollups WHERE contact_id = ? AND currency = ?',
        [$contactId, $currency],
    );
}

// ── Attribution signal ─────────────────────────────────────────────────────────

it('enqueues a coalesced refresh when a paid order is attributed', function () {
    $contactId = p6a12Contact();
    $o = p6a12PaidOrder(5000, 'XOF');
    p6a12Attribute($o['order'], $contactId);

    $row = p6a12Outbox($contactId, 'XOF');
    expect($row)->not->toBeNull()
        ->and((int) $row->requested_generation)->toBe(1)
        ->and((int) $row->processed_generation)->toBe(0)
        ->and($row->terminal_at)->toBeNull();
});

it('enqueues a free (zero total) acquired order too', function () {
    $contactId = p6a12Contact();
    $o = p6a12PaidOrder(0, 'XOF');
    p6a12Attribute($o['order'], $contactId);

    p6a12Process($contactId, 'XOF');
    $rollup = p6a12Rollup($contactId, 'XOF');
    expect($rollup)->not->toBeNull()
        ->and((int) $rollup->acquired_orders_count)->toBe(1)
        ->and((int) $rollup->gross_revenue_minor)->toBe(0)
        ->and((int) $rollup->net_revenue_minor)->toBe(0);
});

it('copies no PII into the outbox row', function () {
    $columns = array_map(
        static fn (object $c): string => $c->column_name,
        p6a12Owner()->select("SELECT column_name FROM information_schema.columns WHERE table_name = 'crm_commerce_rollup_refresh_outbox'"),
    );

    foreach (['email', 'customer_email', 'name', 'phone', 'user_id', 'visitor_id', 'order_id', 'payment_id'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

it('coalesces two attributed orders for the same contact/currency into one row with generation 2', function () {
    $contactId = p6a12Contact();
    $a = p6a12PaidOrder(5000, 'XOF');
    $b = p6a12PaidOrder(3000, 'XOF');
    p6a12Attribute($a['order'], $contactId);
    p6a12Attribute($b['order'], $contactId);

    $row = p6a12Outbox($contactId, 'XOF');
    expect(p6a12OutboxCount($contactId))->toBe(1)
        ->and((int) $row->requested_generation)->toBe(2);
});

it('keeps distinct currencies of one contact in separate rows', function () {
    $contactId = p6a12Contact();
    p6a12Attribute(p6a12PaidOrder(5000, 'XOF')['order'], $contactId);
    p6a12Attribute(p6a12PaidOrder(4000, 'USD')['order'], $contactId);

    expect(p6a12Outbox($contactId, 'XOF'))->not->toBeNull()
        ->and(p6a12Outbox($contactId, 'USD'))->not->toBeNull()
        ->and(p6a12OutboxCount($contactId))->toBe(2);
});

// ── Refund signal ───────────────────────────────────────────────────────────────

it('does not enqueue for a pending or failed refund', function () {
    $contactId = p6a12Contact();
    $o = p6a12PaidOrder(5000, 'XOF');
    p6a12Attribute($o['order'], $contactId);
    $gen0 = (int) p6a12Outbox($contactId, 'XOF')->requested_generation;

    p6a12InsertRefund($o['payment'], 1000, 'pending');
    p6a12InsertRefund($o['payment'], 1000, 'failed');

    expect((int) p6a12Outbox($contactId, 'XOF')->requested_generation)->toBe($gen0);
});

it('enqueues when a refund enters succeeded and aggregates it on refresh', function () {
    $contactId = p6a12Contact();
    $o = p6a12PaidOrder(5000, 'XOF');
    p6a12Attribute($o['order'], $contactId);
    $genBefore = (int) p6a12Outbox($contactId, 'XOF')->requested_generation;

    p6a12SucceedRefund($o['order'], $o['payment'], 2000, 5000);
    expect((int) p6a12Outbox($contactId, 'XOF')->requested_generation)->toBe($genBefore + 1);

    p6a12Process($contactId, 'XOF');
    $rollup = p6a12Rollup($contactId, 'XOF');
    expect((int) $rollup->gross_revenue_minor)->toBe(5000)
        ->and((int) $rollup->refunded_amount_minor)->toBe(2000)
        ->and((int) $rollup->net_revenue_minor)->toBe(3000);
});

it('never invents a contact for a succeeded refund before its attribution exists', function () {
    $contactId = p6a12Contact();
    $o = p6a12PaidOrder(5000, 'XOF');

    // Refund succeeds BEFORE any attribution — nothing enqueued, no contact guessed.
    p6a12SucceedRefund($o['order'], $o['payment'], 2000, 5000);
    expect(p6a12Outbox($contactId, 'XOF'))->toBeNull();

    // Later attribution enqueues, and the refresh already includes the earlier refund.
    p6a12Attribute($o['order'], $contactId);
    expect(p6a12Outbox($contactId, 'XOF'))->not->toBeNull();

    p6a12Process($contactId, 'XOF');
    expect((int) p6a12Rollup($contactId, 'XOF')->refunded_amount_minor)->toBe(2000);
});

// ── Generation-safe processing ──────────────────────────────────────────────────

it('refreshes and advances processed_generation to the observed requested_generation', function () {
    $contactId = p6a12Contact();
    p6a12Attribute(p6a12PaidOrder(5000, 'XOF')['order'], $contactId);

    $result = p6a12Process($contactId, 'XOF');
    expect($result->status)->toBe('refreshed')
        ->and((int) $result->processed_generation)->toBe(1);

    $row = p6a12Outbox($contactId, 'XOF');
    expect((int) $row->processed_generation)->toBe(1)
        ->and((int) $row->requested_generation)->toBe(1);
});

it('is idempotent: a second process with no new generation is a noop', function () {
    $contactId = p6a12Contact();
    p6a12Attribute(p6a12PaidOrder(5000, 'XOF')['order'], $contactId);

    p6a12Process($contactId, 'XOF');
    expect(p6a12Process($contactId, 'XOF')->status)->toBe('noop');
});

it('only lists rows that are due and whose requested generation exceeds the processed one', function () {
    $contactId = p6a12Contact();
    p6a12Attribute(p6a12PaidOrder(5000, 'XOF')['order'], $contactId);

    $due = p6a12Owner()->select('SELECT * FROM list_due_crm_commerce_rollup_refreshes(50)');
    expect(collect($due)->firstWhere('contact_id', $contactId))->not->toBeNull();

    p6a12Process($contactId, 'XOF');

    $dueAfter = p6a12Owner()->select('SELECT * FROM list_due_crm_commerce_rollup_refreshes(50)');
    expect(collect($dueAfter)->firstWhere('contact_id', $contactId))->toBeNull();
});

it('rejects an out-of-range batch size for list_due', function () {
    expect(fn () => p6a12Owner()->select('SELECT * FROM list_due_crm_commerce_rollup_refreshes(0)'))
        ->toThrow(QueryException::class);
    expect(fn () => p6a12Owner()->select('SELECT * FROM list_due_crm_commerce_rollup_refreshes(101)'))
        ->toThrow(QueryException::class);
});
