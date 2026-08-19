<?php

declare(strict_types=1);

use App\Console\Commands\PromoteAffiliateCommissions;
use App\Events\RefundSucceeded;
use App\Jobs\ProcessAffiliateAttribution;
use App\Jobs\ProcessAffiliateCommissionAccrual;
use App\Jobs\ProcessAffiliateRefundReversal;
use App\Listeners\QueueAffiliateRefundReversal;
use App\Services\Affiliate\AffiliateAttributionService;
use App\Services\Affiliate\AffiliateCommissionService;
use App\Services\Affiliate\AffiliateRefundReversalService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

/**
 * P6-D3 — commission accrual, payable promotion and refund reversal (D-068).
 *
 * Read from `pg_catalog`, never `information_schema`: the latter is filtered by privilege,
 * so a contract written against it passes on an empty list and proves nothing.
 *
 * The NON-TRANSACTIONAL harness is required, not preferred: `orders`, `payments` and
 * `refunds` all carry DEFERRABLE constraint triggers that only fire at COMMIT, and several
 * proofs below depend on a real commit. See `P6D2GuestCheckoutSequenceTest` for the full
 * write-up of why `RefreshDatabase` cannot serve that.
 */
uses(InteractsWithCrmDatabase::class);

// ── Fixtures ─────────────────────────────────────────────────────────────────────

function p6d3Affiliate(): array
{
    $userId = (int) Fx::owner()->selectOne(
        "INSERT INTO users (email, password_hash, role, status, created_at, updated_at)
         VALUES ('aff-d3@example.test', 'x', 'customer', 'active', now(), now()) RETURNING id",
    )->id;

    $affiliateId = (int) Fx::owner()->selectOne(
        "INSERT INTO affiliates (public_id, user_id, status, applied_at, approved_at, created_at, updated_at)
         VALUES (gen_random_uuid(), ?, 'active', now(), now(), now(), now()) RETURNING id",
        [$userId],
    )->id;

    Fx::owner()->statement(
        "INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at)
         VALUES (?, 'D3PROBE12345', true, now(), now())",
        [$affiliateId],
    );

    return ['affiliate_id' => $affiliateId, 'user_id' => $userId];
}

function p6d3Policy(int $bps = 1500, int $delayDays = 14, int $version = 1, string $status = 'active'): int
{
    return (int) Fx::owner()->selectOne(
        "INSERT INTO affiliate_program_policies
            (public_id, version, status, attribution_model, attribution_window_days,
             default_commission_bps, payable_delay_days, payout_threshold_minor,
             payout_currency, manual_payout_only, effective_from, created_at, updated_at)
         VALUES (gen_random_uuid(), ?, ?, 'code_then_last_click', 30, ?, ?, 100000, 'XOF',
                 true, now(), now(), now()) RETURNING id",
        [$version, $status, $bps, $delayDays],
    )->id;
}

/** A FRESH visitor each call: one test builds two orders, and `visitors.id` is the PK. */
function p6d3Visitor(): string
{
    return (string) Fx::owner()->selectOne(
        'INSERT INTO visitors (id, created_at, updated_at) VALUES (gen_random_uuid(), now(), now()) RETURNING id',
    )->id;
}

/**
 * An order with its lines, in ONE transaction: `orders` carries DEFERRABLE checks that only
 * fire at COMMIT, so a separate insert per statement would fail with 23514.
 *
 * @param  list<int>  $lineTotals
 * @param  bool  $withProduct  false leaves `product_id` NULL — a deleted product (D-006).
 * @param  int  $taxMinor  `SUM(line_total_minor) + tax = total` is enforced by
 *                         `validate_order_items_consistency`, so a non-zero tax is the ONLY
 *                         way a refund can legally exceed the commissionable base.
 * @param  int  $placedDaysAgo  back-dates the order so `paid_at` can be back-dated too.
 */
function p6d3Order(string $visitorId, array $lineTotals, bool $withProduct = false, int $taxMinor = 0, int $placedDaysAgo = 0): int
{
    return (int) Fx::owner()->transaction(function () use ($visitorId, $lineTotals, $withProduct, $taxMinor, $placedDaysAgo): int {
        $lineSum = array_sum($lineTotals);
        $total = $lineSum + $taxMinor;

        $productId = null;
        if ($withProduct) {
            $productId = (int) Fx::owner()->selectOne(
                "INSERT INTO products (public_id, slug, name, type, status, created_at, updated_at)
                 VALUES (gen_random_uuid(), 'p6d3-probe', 'P6D3 probe', 'ebook', 'published', now(), now())
                 RETURNING id",
            )->id;
        }

        $orderId = (int) Fx::owner()->selectOne(
            "INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, visitor_id,
                                 customer_email, subtotal_minor, discount_minor, tax_minor,
                                 total_minor, currency, status, placed_at, expires_at,
                                 created_at, updated_at)
             VALUES (gen_random_uuid(),
                     'DGT-2026-' || upper(substring(replace(gen_random_uuid()::text, '-', '') FROM 1 FOR 10)),
                     md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text), ?,
                     'buyer@example.test', ?, 0, ?, ?, 'XOF', 'pending',
                     now() - make_interval(days => ?),
                     now() - make_interval(days => ?) + interval '30 minutes', now(), now())
             RETURNING id",
            [$visitorId, $lineSum, $taxMinor, $total, $placedDaysAgo, $placedDaysAgo],
        )->id;

        foreach ($lineTotals as $index => $lineTotal) {
            Fx::owner()->statement(
                "INSERT INTO order_items (order_id, product_id, purchased_product_id,
                                          product_name_snapshot, product_slug_snapshot,
                                          product_type_snapshot, unit_price_minor, quantity,
                                          line_subtotal_minor, line_discount_minor,
                                          line_total_minor, currency, created_at, updated_at)
                 VALUES (?, ?, 1, ?, ?, 'ebook', ?, 1, ?, 0, ?, 'XOF', now(), now())",
                [$orderId, $productId, 'Line '.$index, 'line-'.$index, $lineTotal, $lineTotal, $lineTotal],
            );
        }

        return $orderId;
    });
}

/** Pays the order for real: one succeeded payment, `paid_at` set, status `paid`. */
function p6d3Pay(int $orderId, int $amount, int $paidDaysAgo = 0): int
{
    return (int) Fx::owner()->transaction(function () use ($orderId, $amount, $paidDaysAgo): int {
        $paymentId = (int) Fx::owner()->selectOne(
            "INSERT INTO payments (public_id, order_id, attempt_number, provider,
                                   idempotency_key_hash, amount_minor, currency, status,
                                   initiated_at, succeeded_at, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, 1, 'cinetpay',
                     md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text), ?, 'XOF',
                     'succeeded', now() - make_interval(days => ?), now() - make_interval(days => ?),
                     now(), now()) RETURNING id",
            [$orderId, $amount, $paidDaysAgo, $paidDaysAgo],
        )->id;

        Fx::owner()->statement(
            "UPDATE orders SET status = 'paid', paid_at = now() - make_interval(days => ?), updated_at = now() WHERE id = ?",
            [$paidDaysAgo, $orderId],
        );

        return $paymentId;
    });
}

/** A succeeded refund; the order status moves with it, as the deferred trigger demands. */
function p6d3Refund(int $paymentId, int $orderId, int $amount, bool $full): int
{
    return (int) Fx::owner()->transaction(function () use ($paymentId, $orderId, $amount, $full): int {
        $refundId = (int) Fx::owner()->selectOne(
            "INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash,
                                  amount_minor, currency, status, requested_at, succeeded_at,
                                  created_at, updated_at)
             VALUES (gen_random_uuid(), ?, 'cinetpay',
                     md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text), ?, 'XOF',
                     'succeeded', now(), now(), now(), now()) RETURNING id",
            [$paymentId, $amount],
        )->id;

        Fx::owner()->statement(
            'UPDATE orders SET status = ?, updated_at = now() WHERE id = ?',
            [$full ? 'refunded' : 'partially_refunded', $orderId],
        );

        return $refundId;
    });
}

/** Every authority call goes through the REAL runtime identity, never the owner. */
function p6d3Accrue(int $orderId): string
{
    return (string) DB::selectOne('SELECT public.accrue_affiliate_commissions(?) AS s', [$orderId])->s;
}

function p6d3Attribute(int $orderId): string
{
    return (string) DB::selectOne('SELECT public.resolve_affiliate_attribution(?) AS s', [$orderId])->s;
}

function p6d3Touch(string $visitorId): void
{
    DB::selectOne('SELECT public.record_affiliate_touch(?, NULL, ?, ?) AS s', [$visitorId, 'D3PROBE12345', 'link']);
}

/**
 * Shifts the recorded touch into the past. `record_affiliate_touch` stamps
 * `CURRENT_TIMESTAMP` and takes no date, so a back-dated ORDER would otherwise fall outside
 * the attribution window — `occurred_at <= placed_at <= expires_at` is what P6-D2 enforces.
 */
function p6d3BackdateTouch(int $daysAgo, int $windowDays = 30): void
{
    Fx::owner()->statement(
        'UPDATE affiliate_touches
         SET occurred_at = now() - make_interval(days => ?),
             expires_at  = now() - make_interval(days => ?) + make_interval(days => ?)',
        [$daysAgo, $daysAgo, $windowDays],
    );
}

/** @return list<object> commissions with their live ledger balance. */
function p6d3Commissions(): array
{
    return Fx::owner()->select(<<<'SQL'
        SELECT c.id, c.order_item_id, c.amount_minor, c.status, c.rate_bps_snapshot,
               c.base_amount_minor_snapshot, c.payable_at,
               COALESCE((SELECT SUM(e.amount_minor) FROM affiliate_commission_entries e
                         WHERE e.commission_id = c.id), 0) AS balance
        FROM affiliate_commissions c
        ORDER BY c.id
        SQL);
}

/** The full happy path up to a paid, attributed, accrued order. */
function p6d3PaidAttributedOrder(array $lineTotals, bool $withProduct = false): array
{
    p6d3Affiliate();
    $policyId = p6d3Policy();
    $visitor = p6d3Visitor();
    p6d3Touch($visitor);

    $orderId = p6d3Order($visitor, $lineTotals, $withProduct);
    p6d3Attribute($orderId);
    $paymentId = p6d3Pay($orderId, array_sum($lineTotals));

    return ['order_id' => $orderId, 'payment_id' => $paymentId, 'policy_id' => $policyId];
}

beforeEach(function (): void {
    Config::set('affiliate.governance_enabled', true);
});

// ── 1. The frontier ──────────────────────────────────────────────────────────────

it('owns exactly migration 000033 and installs three ordinary authorities, no trigger', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(50)
        ->and(glob($root.'/database/migrations/2026_07_14_000033*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000035*.php') ?: [])->toBe([]);

    // Ordinary functions. A trigger function would return `trigger`, and P6-D3 attaches
    // nothing to `orders`, `payments` or `refunds` — the same rule D-067 set for P6-D2.
    $functions = array_map(
        static fn (object $r): string => $r->proname.'|'.$r->result,
        Fx::owner()->select(<<<'SQL'
            SELECT p.proname, pg_get_function_result(p.oid) AS result
            FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public'
              AND p.proname IN ('accrue_affiliate_commissions',
                                'promote_affiliate_commissions_to_payable',
                                'apply_affiliate_refund_reversal')
            ORDER BY 1
            SQL),
    );

    expect($functions)->toBe([
        'accrue_affiliate_commissions|text',
        'apply_affiliate_refund_reversal|text',
        'promote_affiliate_commissions_to_payable|integer',
    ]);

    $onCommerce = Fx::owner()->select(<<<'SQL'
        SELECT t.tgname
        FROM pg_trigger AS t
        JOIN pg_class AS c ON c.oid = t.tgrelid
        JOIN pg_proc AS p ON p.oid = t.tgfoid
        WHERE NOT t.tgisinternal
          AND c.relname IN ('orders', 'payments', 'refunds', 'order_items')
          AND (t.tgname LIKE '%affiliate%' OR p.proname LIKE '%affiliate%' OR p.proname LIKE '%commission%')
        SQL);

    expect($onCommerce)->toBe([]);
});

// ── 2. Accrual ───────────────────────────────────────────────────────────────────

it('accrues one commission per line with integer arithmetic and a single ledger entry each', function () {
    // 1500 bps on 3000 and 7000 → 450 and 1050 exactly.
    $ctx = p6d3PaidAttributedOrder([3000, 7000]);

    expect(p6d3Accrue($ctx['order_id']))->toBe('accrued')
        ->and(p6d3Accrue($ctx['order_id']))->toBe('already_accrued');

    $commissions = p6d3Commissions();

    expect($commissions)->toHaveCount(2)
        ->and((int) $commissions[0]->amount_minor)->toBe(450)
        ->and((int) $commissions[1]->amount_minor)->toBe(1050)
        ->and((int) $commissions[0]->base_amount_minor_snapshot)->toBe(3000)
        ->and((int) $commissions[1]->base_amount_minor_snapshot)->toBe(7000)
        ->and($commissions[0]->status)->toBe('pending')
        // Exactly one accrual per commission, and the balance equals the accrual.
        ->and((int) $commissions[0]->balance)->toBe(450)
        ->and((int) $commissions[1]->balance)->toBe(1050);

    $entries = Fx::owner()->select('SELECT entry_type, amount_minor FROM affiliate_commission_entries ORDER BY id');

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->entry_type)->toBe('accrual')
        ->and((int) $entries[0]->amount_minor)->toBeGreaterThan(0);
});

it('anchors payable_at to paid_at plus the snapshotted delay, never to now()', function () {
    p6d3Affiliate();
    p6d3Policy(bps: 1500, delayDays: 14);
    $visitor = p6d3Visitor();
    p6d3Touch($visitor);

    // Placed 60 days ago and paid 30 days ago. `orders_paid_at_after_placement_check` forbids
    // paying before placing, so the WHOLE order is back-dated, not just `paid_at`.
    $orderId = p6d3Order($visitor, [10_000], placedDaysAgo: 60);
    // The touch must precede the order it explains: 61 days ago, still inside its 30-day
    // window when the order was placed on day 60.
    p6d3BackdateTouch(daysAgo: 61);
    p6d3Attribute($orderId);
    p6d3Pay($orderId, 10_000, paidDaysAgo: 30);

    // The accrual runs TODAY. Anchored to `now()` the deadline would land in two weeks;
    // anchored to `paid_at` it is already sixteen days past due.
    expect(p6d3Accrue($orderId))->toBe('accrued');

    $row = Fx::owner()->selectOne(<<<'SQL'
        SELECT c.payable_delay_days_snapshot AS delay,
               c.payable_at <= now() AS already_due,
               abs(extract(epoch FROM (c.payable_at - (o.paid_at + interval '14 days')))) < 2 AS anchored
        FROM affiliate_commissions c JOIN orders o ON o.id = c.order_id
        SQL);

    expect((int) $row->delay)->toBe(14)
        ->and($row->anchored)->toBeTrue()
        // 30 days ago + 14 days is in the past: the commission is due immediately.
        ->and($row->already_due)->toBeTrue();
});

it('creates no commission when the computed amount would be zero', function () {
    // 1500 bps on 6 minor units → 0 after truncation, and on 0 → 0.
    $ctx = p6d3PaidAttributedOrder([6, 0]);

    expect(p6d3Accrue($ctx['order_id']))->toBe('no_commissionable_line')
        ->and(p6d3Commissions())->toBe([])
        // A zero commission could never receive its accrual (the ledger forbids 0), so it
        // would sit forever in a state with no legal transition. No row at all instead.
        ->and(Fx::owner()->select('SELECT 1 FROM affiliate_commission_entries'))->toBe([]);
});

it('refuses to accrue an unpaid, unattributed or unknown order', function () {
    p6d3Affiliate();
    p6d3Policy();
    $visitor = p6d3Visitor();

    $orderId = p6d3Order($visitor, [10_000]);

    // No touch, so no attribution.
    expect(p6d3Attribute($orderId))->toBe('no_match')
        ->and(p6d3Accrue($orderId))->toBe('not_paid');

    p6d3Pay($orderId, 10_000);

    expect(p6d3Accrue($orderId))->toBe('no_attribution')
        ->and(p6d3Accrue(999_999))->toBe('no_such_order')
        ->and(p6d3Commissions())->toBe([]);
});

it('snapshots the rate of the attribution policy, not of a newer active one', function () {
    p6d3Affiliate();
    $oldPolicy = p6d3Policy(bps: 1500, version: 1);
    $visitor = p6d3Visitor();
    p6d3Touch($visitor);

    $orderId = p6d3Order($visitor, [10_000]);
    p6d3Attribute($orderId);
    p6d3Pay($orderId, 10_000);

    // A new policy is published at a different rate BEFORE the accrual runs.
    Fx::owner()->statement("UPDATE affiliate_program_policies SET status = 'superseded' WHERE id = ?", [$oldPolicy]);
    p6d3Policy(bps: 500, version: 2);

    expect(p6d3Accrue($orderId))->toBe('accrued');

    $commission = p6d3Commissions()[0];

    // The sale is explained by the rules in force when it happened. That is the whole point
    // of versioned policies: publishing a new version must never rewrite yesterday.
    expect((int) $commission->rate_bps_snapshot)->toBe(1500)
        ->and((int) $commission->amount_minor)->toBe(1500);
});

// ── 3. Payable promotion ─────────────────────────────────────────────────────────

it('promotes only due commissions, and writes no ledger entry doing so', function () {
    $ctx = p6d3PaidAttributedOrder([3000, 7000]);
    p6d3Accrue($ctx['order_id']);

    $before = (int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_commission_entries')->c;

    $promoted = static fn (): int => (int) DB::selectOne(
        'SELECT public.promote_affiliate_commissions_to_payable(?) AS p', [100],
    )->p;

    // Deadline is 14 days out: nothing is due.
    expect($promoted())->toBe(0);

    Fx::owner()->statement("UPDATE affiliate_commissions SET payable_at = now() - interval '1 day'");

    expect($promoted())->toBe(2)
        // Idempotent: a second sweep finds nothing left in `pending`.
        ->and($promoted())->toBe(0);

    $statuses = array_map(static fn (object $c): string => $c->status, p6d3Commissions());

    expect($statuses)->toBe(['payable', 'payable'])
        // ⚠️ NO `release` entry. `release` is a POSITIVE type and the balance is SUM(entries):
        // emitting one on top of the accrual would credit the same money twice. Becoming
        // payable is a change of STATE, not a movement of money.
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_commission_entries')->c)
        ->toBe($before);
});

it('refuses an out-of-range sweep limit instead of silently clamping it', function () {
    foreach ([0, -1, 1001] as $limit) {
        expect(fn () => DB::selectOne('SELECT public.promote_affiliate_commissions_to_payable(?) AS p', [$limit]))
            ->toThrow(QueryException::class);
    }
});

// ── 4. Refund reversal ───────────────────────────────────────────────────────────

it('reverses a partial refund proportionally through the Hamilton allocation', function () {
    $ctx = p6d3PaidAttributedOrder([3000, 7000]);
    p6d3Accrue($ctx['order_id']);

    // Half the order is refunded: Hamilton splits 5000 into 1500 / 3500.
    $refundId = p6d3Refund($ctx['payment_id'], $ctx['order_id'], 5000, full: false);

    expect(app(AffiliateRefundReversalService::class)->reverseForRefund($refundId))->toBe('reversed');

    $commissions = p6d3Commissions();

    // 1500 × 1500bps = 225 ; 3500 × 1500bps = 525 — exactly half of each accrual.
    expect((int) $commissions[0]->balance)->toBe(225)
        ->and((int) $commissions[1]->balance)->toBe(525)
        // Still live: a partial refund does not cancel a commission.
        ->and($commissions[0]->status)->toBe('pending');

    $reversals = Fx::owner()->select(
        "SELECT amount_minor, refund_id FROM affiliate_commission_entries WHERE entry_type = 'refund_reversal' ORDER BY id",
    );

    expect($reversals)->toHaveCount(2)
        // Signed: a reversal debits.
        ->and((int) $reversals[0]->amount_minor)->toBe(-225)
        ->and((int) $reversals[0]->refund_id)->toBe($refundId);
});

it('reverses a full refund down to a zero balance and cancels the commission', function () {
    $ctx = p6d3PaidAttributedOrder([3333, 6667]);
    p6d3Accrue($ctx['order_id']);

    $refundId = p6d3Refund($ctx['payment_id'], $ctx['order_id'], 10_000, full: true);

    expect(app(AffiliateRefundReversalService::class)->reverseForRefund($refundId))->toBe('reversed');

    foreach (p6d3Commissions() as $commission) {
        // The ENTIRE remaining balance, not a truncated share: a sale returned in full must
        // leave the affiliate holding exactly nothing, whatever the rounding along the way.
        expect((int) $commission->balance)->toBe(0)
            ->and($commission->status)->toBe('cancelled');
    }

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_commissions WHERE cancelled_at IS NULL')->c)
        ->toBe(0);
});

it('never reverses the same refund twice, nor more than was accrued', function () {
    $ctx = p6d3PaidAttributedOrder([10_000]);
    p6d3Accrue($ctx['order_id']);

    $refundId = p6d3Refund($ctx['payment_id'], $ctx['order_id'], 10_000, full: true);
    $service = app(AffiliateRefundReversalService::class);

    expect($service->reverseForRefund($refundId))->toBe('reversed')
        ->and($service->reverseForRefund($refundId))->toBe('already_reversed');

    expect((int) p6d3Commissions()[0]->balance)->toBe(0);

    // And the storage layer refuses a forged second reversal even from the OWNER.
    $commissionId = (int) p6d3Commissions()[0]->id;

    expect(fn () => Fx::owner()->statement(
        "INSERT INTO affiliate_commission_entries (affiliate_id, commission_id, entry_type,
             amount_minor, currency, refund_id, occurred_at, created_at)
         SELECT affiliate_id, ?, 'refund_reversal', -1, currency, ?, now(), now()
         FROM affiliate_commissions WHERE id = ?",
        [$commissionId, $refundId, $commissionId],
    ))->toThrow(QueryException::class);
});

it('refuses a reversal for an unknown refund, an unsucceeded one, or an order with no commission', function () {
    $service = app(AffiliateRefundReversalService::class);
    $ctx = p6d3PaidAttributedOrder([10_000]);

    expect($service->reverseForRefund(999_999))->toBe('no_such_refund');

    // A refund that never succeeded moves no money, so it reverses nothing.
    $pendingRefundId = (int) Fx::owner()->selectOne(
        "INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor,
                              currency, status, requested_at, created_at, updated_at)
         VALUES (gen_random_uuid(), ?, 'cinetpay',
                 md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text), 1000, 'XOF',
                 'pending', now(), now(), now()) RETURNING id",
        [$ctx['payment_id']],
    )->id;

    expect($service->reverseForRefund($pendingRefundId))->toBe('refund_not_succeeded');

    // Succeeded, but the accrual never ran: nothing to reverse.
    $refundId = p6d3Refund($ctx['payment_id'], $ctx['order_id'], 5000, full: false);

    expect($service->reverseForRefund($refundId))->toBe('no_commissions');
});

it('refuses an allocation that is not a json object or carries a negative amount', function () {
    $ctx = p6d3PaidAttributedOrder([10_000]);
    p6d3Accrue($ctx['order_id']);
    $refundId = p6d3Refund($ctx['payment_id'], $ctx['order_id'], 5000, full: false);

    $itemId = (int) p6d3Commissions()[0]->order_item_id;

    foreach (['[]', '"x"', '123'] as $notAnObject) {
        expect(fn () => DB::selectOne(
            'SELECT public.apply_affiliate_refund_reversal(?, ?::jsonb) AS s', [$refundId, $notAnObject],
        ))->toThrow(QueryException::class);
    }

    expect(fn () => DB::selectOne(
        'SELECT public.apply_affiliate_refund_reversal(?, ?::jsonb) AS s',
        [$refundId, json_encode([(string) $itemId => -1])],
    ))->toThrow(QueryException::class);
});

// ── 5. The Hamilton adapter ──────────────────────────────────────────────────────

it('allocates across lines whose product was deleted, using the line id as sort substitute', function () {
    // `withProduct: false` leaves `product_id` NULL — the allocator refuses `product_id < 1`,
    // so without the substitution this whole path would throw.
    $ctx = p6d3PaidAttributedOrder([3000, 7000], withProduct: false);
    p6d3Accrue($ctx['order_id']);

    expect(Fx::owner()->selectOne('SELECT count(*) AS c FROM order_items WHERE product_id IS NULL')->c)->toBe(2);

    $refundId = p6d3Refund($ctx['payment_id'], $ctx['order_id'], 5000, full: false);

    expect(app(AffiliateRefundReversalService::class)->reverseForRefund($refundId))->toBe('reversed')
        ->and((int) p6d3Commissions()[0]->balance)->toBe(225);
});

it('caps the allocation at the sum of the line totals, never orders.total_minor', function () {
    // `validate_order_items_consistency` pins `SUM(line_total_minor) + tax = total`, so a
    // NON-ZERO TAX is the only way a refund can legally exceed the commissionable base — and
    // it is exactly the case this cap exists for. `order_items` is immutable (its own
    // trigger says so), which is why the tax is built at creation and never patched in.
    p6d3Affiliate();
    p6d3Policy();
    $visitor = p6d3Visitor();
    p6d3Touch($visitor);

    $orderId = p6d3Order($visitor, [8000], taxMinor: 2000);
    p6d3Attribute($orderId);
    $paymentId = p6d3Pay($orderId, 10_000);

    expect(p6d3Accrue($orderId))->toBe('accrued')
        // 8000 × 1500 bps — the tax never entered the commission base.
        ->and((int) p6d3Commissions()[0]->amount_minor)->toBe(1200);

    // A partial refund of 9000 exceeds the 8000 the lines can absorb. Without the cap the
    // allocator refuses the whole call (`a discount cannot exceed the eligible subtotal`)
    // and no reversal is ever written.
    $refundId = p6d3Refund($paymentId, $orderId, 9000, full: false);

    expect(app(AffiliateRefundReversalService::class)->reverseForRefund($refundId))->toBe('reversed');

    // Capped to 8000 and reversed at the same rate: the whole accrual, never more.
    expect((int) p6d3Commissions()[0]->balance)->toBe(0)
        ->and(p6d3Commissions()[0]->status)->toBe('cancelled');
});

// ── 6. The privilege frontier ────────────────────────────────────────────────────

it('gives the runtime EXECUTE on the three authorities and nothing else', function () {
    $signatures = [
        'public.accrue_affiliate_commissions(bigint)',
        'public.promote_affiliate_commissions_to_payable(integer)',
        'public.apply_affiliate_refund_reversal(bigint, jsonb)',
    ];

    foreach ($signatures as $signature) {
        expect(DB::selectOne("SELECT has_function_privilege(current_user, '{$signature}', 'EXECUTE') AS v")->v)->toBeTrue()
            ->and(DB::selectOne("SELECT has_function_privilege('public', '{$signature}', 'EXECUTE') AS v")->v)->toBeFalse();
    }

    $meta = Fx::owner()->select(<<<'SQL'
        SELECT p.proname, pg_get_userbyid(p.proowner) AS owner, p.prosecdef,
               array_to_string(p.proconfig, ',') AS cfg
        FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public'
          AND p.proname IN ('accrue_affiliate_commissions',
                            'promote_affiliate_commissions_to_payable',
                            'apply_affiliate_refund_reversal')
        SQL);

    expect($meta)->toHaveCount(3);

    foreach ($meta as $row) {
        expect($row->owner)->toBe('digitrove_affiliate_executor')
            ->and($row->prosecdef)->toBeTrue()
            ->and($row->cfg)->toBe('search_path=pg_catalog, public, pg_temp');
    }

    // The D-058 frontier is untouched: the authorities are the only door.
    foreach (['affiliate_commissions', 'affiliate_commission_entries'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect(DB::selectOne("SELECT has_table_privilege(current_user, '{$table}', '{$privilege}') AS v")->v)
                ->toBeFalse("runtime must not hold {$privilege} on {$table}");
        }
    }
});

it('lets the affiliate executor read only the commerce columns its authorities name', function () {
    $granted = [];

    foreach (['orders', 'order_items', 'refunds', 'payments'] as $table) {
        $granted[$table] = array_map(
            static fn (object $r): string => $r->column_name,
            Fx::owner()->select(<<<'SQL'
                SELECT a.attname AS column_name
                FROM pg_attribute AS a
                JOIN pg_class AS c ON c.oid = a.attrelid
                JOIN pg_namespace AS n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = ? AND a.attnum > 0 AND NOT a.attisdropped
                  AND has_column_privilege('digitrove_affiliate_executor', c.oid, a.attnum, 'SELECT')
                ORDER BY 1
                SQL, [$table]),
        );
    }

    // Never `customer_email`, never a coupon snapshot, never an amount it does not compute
    // from. `orders` keeps the four columns P6-D2 granted plus the two P6-D3 needs.
    expect($granted['orders'])->toBe(['id', 'paid_at', 'placed_at', 'status', 'user_id', 'visitor_id'])
        ->and($granted['order_items'])->toBe(['currency', 'id', 'line_total_minor', 'order_id'])
        ->and($granted['refunds'])->toBe(['id', 'payment_id', 'status'])
        ->and($granted['payments'])->toBe(['id', 'order_id']);
});

// ── 7. The application layer ─────────────────────────────────────────────────────

it('chains the accrual job only when an attribution actually exists', function () {
    Queue::fake();

    $ctx = p6d3PaidAttributedOrder([10_000]);
    $attributions = app(AffiliateAttributionService::class);

    // `already_attributed` — the attribution was resolved by the fixture.
    (new ProcessAffiliateAttribution($ctx['order_id']))->handle($attributions);

    Queue::assertPushed(ProcessAffiliateCommissionAccrual::class, 1);

    // An order with no attribution resolves to `no_match` and must chain NOTHING: queueing
    // an accrual there would only produce work that can never succeed.
    $orphan = p6d3Order(p6d3Visitor(), [5000]);
    (new ProcessAffiliateAttribution($orphan))->handle($attributions);

    Queue::assertPushed(ProcessAffiliateCommissionAccrual::class, 1);
});

it('dispatches a refund-id-only job from RefundSucceeded, and nothing when the programme is off', function () {
    Queue::fake();

    (new QueueAffiliateRefundReversal)->handle(new RefundSucceeded(77));

    Queue::assertPushed(ProcessAffiliateRefundReversal::class, fn ($job): bool => $job->refundId === 77);

    // The payload is the id and ONLY the id.
    $parameters = (new ReflectionClass(ProcessAffiliateRefundReversal::class))->getConstructor()->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getName())->toBe('refundId')
        ->and((string) $parameters[0]->getType())->toBe('int');

    Config::set('affiliate.governance_enabled', false);
    (new QueueAffiliateRefundReversal)->handle(new RefundSucceeded(78));

    Queue::assertPushed(ProcessAffiliateRefundReversal::class, 1);
});

it('keeps the kill switch effective on jobs that were already queued', function () {
    $ctx = p6d3PaidAttributedOrder([10_000]);

    Config::set('affiliate.governance_enabled', false);

    expect(app(AffiliateCommissionService::class)->accrueForOrder($ctx['order_id']))->toBe('disabled')
        ->and(app(AffiliateCommissionService::class)->promoteDue(100))->toBe(0)
        ->and(p6d3Commissions())->toBe([]);

    $refundId = p6d3Refund($ctx['payment_id'], $ctx['order_id'], 5000, full: false);

    expect(app(AffiliateRefundReversalService::class)->reverseForRefund($refundId))->toBe('disabled');

    Config::set('affiliate.governance_enabled', true);

    expect(app(AffiliateCommissionService::class)->accrueForOrder($ctx['order_id']))->toBe('accrued');
});

it('bounds both jobs like every other id-only job in the repository', function () {
    foreach ([new ProcessAffiliateCommissionAccrual(7), new ProcessAffiliateRefundReversal(7)] as $job) {
        expect($job->uniqueId())->toBe('7')
            ->and($job->tries)->toBe(5)
            ->and($job->uniqueFor)->toBe(3600)
            ->and($job->backoff())->toBe([30, 120, 300])
            // A retry must not outlive the uniqueness lock, or a second job could start for
            // the same subject while the first is still retrying.
            ->and($job->tries * ($job->timeout + max($job->backoff())))->toBeLessThan($job->uniqueFor);
    }
});

it('promotes through the operator command and stays silent when the programme is off', function () {
    $ctx = p6d3PaidAttributedOrder([10_000]);
    p6d3Accrue($ctx['order_id']);
    Fx::owner()->statement("UPDATE affiliate_commissions SET payable_at = now() - interval '1 day'");

    $this->artisan(PromoteAffiliateCommissions::class)->expectsOutput('promoted=1')->assertSuccessful();

    // An out-of-range limit fails loudly rather than clamping.
    $this->artisan(PromoteAffiliateCommissions::class, ['--limit' => 5000])->expectsOutput('promoted=0')->assertFailed();

    Config::set('affiliate.governance_enabled', false);
    $this->artisan(PromoteAffiliateCommissions::class)->expectsOutput('promoted=0')->assertSuccessful();
});
