<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\AffiliateFixtures as Aff;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-D0 cross-coherence contract (§6 / §7).
 *
 * Separate foreign keys only prove each id EXISTS. They never prove the ids belong
 * TOGETHER: a commission could reference order 7 and a line of order 9 and satisfy every
 * single-column key. Composite keys close that, so the incoherence is refused by
 * PostgreSQL instead of being caught — or not — by a service written months from now.
 */

/** A second, unrelated attributable order, for building deliberately mismatched rows. */
function p6d0Foreign(): array
{
    return Aff::attributableOrder();
}

// ── Commission ↔ order ↔ line ↔ attribution ↔ affiliate ─────────────────────────

it('refuses a commission whose line belongs to another order', function () {
    $ctx = Aff::attributableOrder();
    $other = p6d0Foreign();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);

    // Every single-column FK below is satisfied: the order exists, the line exists.
    // Only the composite key knows they do not belong to each other.
    $mismatched = ['order_item_id' => $other['order_item_id']] + $ctx;

    expect(fn () => Aff::commission($mismatched, $attributionId, amount: 750, base: 5000))
        ->toThrow(QueryException::class);
});

it('refuses a commission whose attribution covers another order', function () {
    $ctx = Aff::attributableOrder();
    $other = p6d0Foreign();

    Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $foreignAttribution = Aff::attribution($other['order_id'], $other['affiliate_id'], $other['code_id'], $other['policy_id']);

    expect(fn () => Aff::commission($ctx, $foreignAttribution, amount: 750, base: 5000))
        ->toThrow(QueryException::class);
});

it('refuses a commission that credits an affiliate the attribution never named', function () {
    $ctx = Aff::attributableOrder();
    $other = p6d0Foreign();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);

    // Same order, same line, same attribution — but paying somebody else.
    $hijacked = ['affiliate_id' => $other['affiliate_id']] + $ctx;

    expect(fn () => Aff::commission($hijacked, $attributionId, amount: 750, base: 5000))
        ->toThrow(QueryException::class);
});

it('accepts the coherent commission', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);

    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);

    $row = Fx::owner()->selectOne(<<<'SQL'
        SELECT c.amount_minor, c.base_amount_minor_snapshot, c.currency, oi.line_total_minor, oi.currency AS line_currency
        FROM affiliate_commissions AS c
        JOIN order_items AS oi ON oi.id = c.order_item_id
        WHERE c.id = ?
        SQL, [$commissionId]);

    // D-057: the base IS the after-discount line total, which `order_items` already
    // stores net of discount — no allocation is reinvented here.
    expect((int) $row->base_amount_minor_snapshot)->toBe((int) $row->line_total_minor)
        ->and($row->currency)->toBe($row->line_currency)
        ->and((int) $row->amount_minor)->toBeLessThanOrEqual((int) $row->base_amount_minor_snapshot);
});

// ── Ledger coherence ────────────────────────────────────────────────────────────

it('refuses a ledger entry that credits a different affiliate than its commission', function () {
    $ctx = Aff::attributableOrder();
    $other = p6d0Foreign();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);

    expect(fn () => Aff::entry($other['affiliate_id'], $commissionId, 'accrual', 750))
        ->toThrow(QueryException::class);
});

it('refuses a ledger entry that changes currency along the way', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000, currency: 'XOF');

    expect(fn () => Aff::entry($ctx['affiliate_id'], $commissionId, 'accrual', 750, currency: 'USD'))
        ->toThrow(QueryException::class);
});

it('allows a standalone administrative adjustment with no commission', function () {
    $affiliateId = Aff::affiliate(Aff::user(), 'active');

    $entryId = Aff::entry($affiliateId, null, 'admin_adjustment', -500, reasonCode: 'goodwill_correction');

    expect($entryId)->toBeGreaterThan(0);
});

// ── Idempotency identities ──────────────────────────────────────────────────────

it('accrues a commission exactly once, however often a worker retries', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);

    Aff::entry($ctx['affiliate_id'], $commissionId, 'accrual', 750);

    // A replayed accrual is the classic double payment. The storage layer refuses it.
    expect(fn () => Aff::entry($ctx['affiliate_id'], $commissionId, 'accrual', 750))
        ->toThrow(QueryException::class);

    // Other movements on the same commission remain possible.
    expect(Aff::entry($ctx['affiliate_id'], $commissionId, 'release', 750))->toBeGreaterThan(0);
});

it('reverses a given refund against a given commission exactly once', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);
    $refundId = Aff::refund($ctx);

    Aff::entry($ctx['affiliate_id'], $commissionId, 'refund_reversal', -300, refundId: $refundId);

    expect(fn () => Aff::entry($ctx['affiliate_id'], $commissionId, 'refund_reversal', -300, refundId: $refundId))
        ->toThrow(QueryException::class);
});

// ── Payout: single affiliate, single currency, structurally ─────────────────────

it('refuses to pay another affiliate commission inside a payout', function () {
    $ctx = Aff::attributableOrder();
    $other = p6d0Foreign();

    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $mine = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);

    $foreignAttribution = Aff::attribution($other['order_id'], $other['affiliate_id'], $other['code_id'], $other['policy_id']);
    $theirs = Aff::commission($other, $foreignAttribution, amount: 750, base: 5000);

    $payoutId = Aff::payout($ctx['affiliate_id'], $ctx['policy_id'], 10_000);

    expect(Aff::payoutItem($payoutId, $mine, 750))->toBeGreaterThan(0);

    // Paying affiliate B's commission out of affiliate A's payout is now impossible.
    expect(fn () => Aff::payoutItem($payoutId, $theirs, 750))->toThrow(QueryException::class);
    // Nor by claiming the item belongs to the other affiliate.
    expect(fn () => Aff::payoutItem($payoutId, $theirs, 750, affiliateId: $other['affiliate_id']))
        ->toThrow(QueryException::class);
});

it('refuses to mix currencies inside one payout', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000, currency: 'XOF');

    $payoutId = Aff::payout($ctx['affiliate_id'], $ctx['policy_id'], 10_000, currency: 'XOF');

    // An item in another currency contradicts both its payout and its commission. No
    // exchange rate can be invented to reach a withdrawal threshold.
    expect(fn () => Aff::payoutItem($payoutId, $commissionId, 750, currency: 'USD'))
        ->toThrow(QueryException::class);
});

// ── The schema stores a Hamilton allocation without loss ────────────────────────

/**
 * P6-D0 does NOT implement refund allocation — that engine is P6-D3, and it must reuse the
 * repository's existing authority (`App\Services\Pricing\DiscountAllocator`, D-030 Q3).
 * What is proven here is only that the schema can RECORD such an allocation exactly:
 * integers, per line, signed, summing to the refunded amount with nothing lost.
 */
it('records a per-line allocation exactly, with no rounding loss and no float', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);
    $refundId = Aff::refund($ctx, amount: 2_000);

    Aff::entry($ctx['affiliate_id'], $commissionId, 'accrual', 750);
    // 15 % of a 2 000 partial refund = 300. A largest-remainder split would land on
    // integers exactly like this one; the schema stores whatever P6-D3 decides.
    Aff::entry($ctx['affiliate_id'], $commissionId, 'refund_reversal', -300, refundId: $refundId);

    $balance = Fx::owner()->selectOne(
        'SELECT sum(amount_minor) AS total, count(*) AS entries FROM affiliate_commission_entries WHERE commission_id = ?',
        [$commissionId],
    );

    expect((int) $balance->total)->toBe(450)
        ->and((int) $balance->entries)->toBe(2)
        // The sum is computed by PostgreSQL on BIGINT: an exact integer, never a float.
        ->and(Fx::owner()->selectOne(
            'SELECT pg_typeof(sum(amount_minor))::text AS t FROM affiliate_commission_entries WHERE commission_id = ?',
            [$commissionId],
        )->t)->toBe('numeric');
});
