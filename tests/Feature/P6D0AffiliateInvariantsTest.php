<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\AffiliateFixtures as Aff;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

// ── Identity ────────────────────────────────────────────────────────────────────

it('allows a user exactly one affiliate', function () {
    $userId = Aff::user();
    Aff::affiliate($userId);

    // D-014: affiliation is attached to a user. A second row would split their balance.
    expect(fn () => Aff::affiliate($userId))->toThrow(QueryException::class);
});

it('requires the timestamp that matches each affiliate status', function () {
    $affiliateId = Aff::affiliate(Aff::user());

    foreach (['active' => 'approved_at', 'suspended' => 'suspended_at', 'rejected' => 'rejected_at', 'closed' => 'closed_at'] as $status => $column) {
        expect(fn () => Fx::owner()->update(
            'UPDATE affiliates SET status = ? WHERE id = ?', [$status, $affiliateId],
        ))->toThrow(QueryException::class, 'affiliates_status_timestamps_check');
    }

    expect(fn () => Fx::owner()->update(
        "UPDATE affiliates SET status = 'ambassador' WHERE id = ?", [$affiliateId],
    ))->toThrow(QueryException::class);
});

// ── Codes: public identifiers, not secrets ──────────────────────────────────────

it('normalises the code and keeps it globally unique', function () {
    $a = Aff::affiliate(Aff::user());
    $b = Aff::affiliate(Aff::user());

    Aff::code($a, 'PROMO2026');

    // Two affiliates cannot share a code: attribution would be ambiguous.
    expect(fn () => Aff::code($b, 'PROMO2026'))->toThrow(QueryException::class);

    // Lowercase, punctuation and anything e-mail shaped are refused by the format CHECK.
    foreach (['promo2026', 'PROMO 2026', 'a@b.com', 'AB', 'PROMO-2026'] as $malformed) {
        expect(fn () => Aff::code($b, $malformed))->toThrow(QueryException::class);
    }
});

it('deactivates a code without deleting it', function () {
    $affiliateId = Aff::affiliate(Aff::user());
    $codeId = Aff::code($affiliateId, 'KEEPME');

    // Marking inactive requires recording when — history stays intact.
    expect(fn () => Fx::owner()->update(
        'UPDATE affiliate_codes SET is_active = false WHERE id = ?', [$codeId],
    ))->toThrow(QueryException::class);

    Fx::owner()->update('UPDATE affiliate_codes SET is_active = false, deactivated_at = now() WHERE id = ?', [$codeId]);

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_codes WHERE id = ?', [$codeId])->c)->toBe(1);
});

// ── Touches ─────────────────────────────────────────────────────────────────────

it('anchors every touch to a subject and to a bounded window', function () {
    $affiliateId = Aff::affiliate(Aff::user());
    $codeId = Aff::code($affiliateId, 'TOUCH01');
    $buyerId = Aff::user();

    Aff::touch($affiliateId, $codeId, userId: $buyerId);

    // A touch with neither visitor nor user could never be matched to an order.
    expect(fn () => Aff::touch($affiliateId, $codeId))->toThrow(QueryException::class);

    // The window is materialised per touch, so changing the policy later cannot move it.
    expect(fn () => Fx::owner()->insert(
        "INSERT INTO affiliate_touches (affiliate_id, affiliate_code_id, user_id, source, occurred_at, expires_at, created_at, updated_at)
         VALUES (?, ?, ?, 'code', now(), now() - interval '1 day', now(), now())",
        [$affiliateId, $codeId, $buyerId],
    ))->toThrow(QueryException::class);

    expect(fn () => Fx::owner()->insert(
        "INSERT INTO affiliate_touches (affiliate_id, affiliate_code_id, user_id, source, occurred_at, expires_at, created_at, updated_at)
         VALUES (?, ?, ?, 'utm', now(), now() + interval '30 days', now(), now())",
        [$affiliateId, $codeId, $buyerId],
    ))->toThrow(QueryException::class);
});

// ── Attribution ─────────────────────────────────────────────────────────────────

it('allows exactly one authoritative attribution per order', function () {
    $ctx = Aff::attributableOrder();

    Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);

    // Two affiliates cannot both be paid for the same sale.
    $other = Aff::affiliate(Aff::user());
    $otherCode = Aff::code($other, 'OTHER01');

    expect(fn () => Aff::attribution($ctx['order_id'], $other, $otherCode, $ctx['policy_id']))
        ->toThrow(QueryException::class);
});

it('requires a touch when the match came from a click', function () {
    $ctx = Aff::attributableOrder();

    // A click-matched attribution must name the touch that produced it.
    expect(fn () => Fx::owner()->insert(
        "INSERT INTO affiliate_attributions (order_id, affiliate_id, affiliate_code_id, affiliate_touch_id, policy_id, matched_by, attributed_at, created_at, updated_at)
         VALUES (?, ?, ?, NULL, ?, 'last_click', now(), now(), now())",
        [$ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']],
    ))->toThrow(QueryException::class);

    // A typed code is its own evidence.
    Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
});

// ── Commissions ─────────────────────────────────────────────────────────────────

it('commissions an order line at most once and never negatively', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);

    Aff::commission($ctx, $attributionId, amount: 750, base: 5000);

    // A line cannot be commissioned twice: that is how double payment happens.
    expect(fn () => Aff::commission($ctx, $attributionId, amount: 750, base: 5000))
        ->toThrow(QueryException::class);
});

it('refuses an absurd snapshot on a commission', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);

    // A commission itself is never negative — corrections live in the ledger.
    expect(fn () => Aff::commission($ctx, $attributionId, amount: -1, base: 5000))->toThrow(QueryException::class);
    expect(fn () => Aff::commission($ctx, $attributionId, amount: 750, base: -1))->toThrow(QueryException::class);
    expect(fn () => Aff::commission($ctx, $attributionId, amount: 750, base: 5000, rateBps: 10000))->toThrow(QueryException::class);
    expect(fn () => Aff::commission($ctx, $attributionId, amount: 750, base: 5000, currency: 'xof'))->toThrow(QueryException::class);
    // No tax policy exists in this repository, so no other base may be claimed.
    expect(fn () => Aff::commission($ctx, $attributionId, amount: 750, base: 5000, baseKind: 'line_total_before_tax'))
        ->toThrow(QueryException::class);
    // A commission can never exceed the line it was computed from: a P6-D3 calculation
    // bug is stopped by the database instead of being paid out.
    expect(fn () => Aff::commission($ctx, $attributionId, amount: 5001, base: 5000))->toThrow(QueryException::class);

    // The arbitrated 15 % of a 5 000 line is accepted.
    expect(Aff::commission($ctx, $attributionId, amount: 750, base: 5000))->toBeGreaterThan(0);
});

// ── The ledger is the financial proof ───────────────────────────────────────────

it('is append-only: an entry can never be updated or deleted', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);
    $entryId = Aff::entry($ctx['affiliate_id'], $commissionId, 'accrual', 750);

    // Even the OWNER cannot rewrite history.
    expect(fn () => Fx::owner()->update(
        'UPDATE affiliate_commission_entries SET amount_minor = 1 WHERE id = ?', [$entryId],
    ))->toThrow(QueryException::class, 'append-only');

    expect(fn () => Fx::owner()->delete(
        'DELETE FROM affiliate_commission_entries WHERE id = ?', [$entryId],
    ))->toThrow(QueryException::class, 'append-only');
});

/**
 * A refund is compensated by a NEW entry, never by erasing the accrual. That is what
 * makes a negative balance auditable instead of invisible.
 */
it('records a refund as a signed compensating entry', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);

    Aff::entry($ctx['affiliate_id'], $commissionId, 'accrual', 750);
    Aff::entry($ctx['affiliate_id'], $commissionId, 'refund_reversal', -750, refundId: Aff::refund($ctx));

    $balance = (int) Fx::owner()->selectOne(
        'SELECT COALESCE(sum(amount_minor), 0) AS b FROM affiliate_commission_entries WHERE affiliate_id = ?',
        [$ctx['affiliate_id']],
    )->b;

    // Both movements survive; the balance is their sum, and it can go negative.
    expect($balance)->toBe(0)
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_commission_entries')->c)->toBe(2);
});

it('enforces entry direction, scope and justification', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);
    $affiliateId = $ctx['affiliate_id'];

    // A zero movement is not an event.
    expect(fn () => Aff::entry($affiliateId, $commissionId, 'accrual', 0))->toThrow(QueryException::class);
    // An accrual credits; it cannot debit.
    expect(fn () => Aff::entry($affiliateId, $commissionId, 'accrual', -750))->toThrow(QueryException::class);
    // A reversal debits; it cannot credit.
    expect(fn () => Aff::entry($affiliateId, $commissionId, 'refund_reversal', 750, refundId: Aff::refund($ctx)))
        ->toThrow(QueryException::class);
    // Only a refund reversal may name a refund…
    expect(fn () => Aff::entry($affiliateId, $commissionId, 'accrual', 750, refundId: Aff::refund($ctx)))
        ->toThrow(QueryException::class);
    // …and it must name one.
    expect(fn () => Aff::entry($affiliateId, $commissionId, 'refund_reversal', -750))->toThrow(QueryException::class);
    // An administrative adjustment is always justified.
    expect(fn () => Aff::entry($affiliateId, $commissionId, 'admin_adjustment', -100))->toThrow(QueryException::class);
    // An unreviewed entry type cannot be smuggled in.
    expect(fn () => Aff::entry($affiliateId, $commissionId, 'bonus', 100))->toThrow(QueryException::class);
});

// ── Payouts ─────────────────────────────────────────────────────────────────────

it('keeps a payout single-affiliate, single-currency and positive', function () {
    $affiliateId = Aff::affiliate(Aff::user());
    $policyId = Aff::policy();

    Aff::payout($affiliateId, $policyId, amount: 15000);

    expect(fn () => Aff::payout($affiliateId, $policyId, amount: 0))->toThrow(QueryException::class);
    expect(fn () => Aff::payout($affiliateId, $policyId, amount: -1))->toThrow(QueryException::class);
    expect(fn () => Aff::payout($affiliateId, $policyId, amount: 15000, currency: 'xof'))->toThrow(QueryException::class);

    // A payout row carries ONE affiliate and ONE currency by construction: there is no
    // column that could hold a second of either, so no conversion can creep in.
    $columns = array_map(
        static fn (object $r): string => (string) $r->column_name,
        Fx::owner()->select("SELECT column_name FROM information_schema.columns WHERE table_name = 'affiliate_payouts'"),
    );

    expect(array_filter($columns, fn (string $c): bool => str_contains($c, 'currency')))->toHaveCount(1)
        ->and(array_filter($columns, fn (string $c): bool => str_contains($c, 'affiliate_id')))->toHaveCount(1)
        ->and($columns)->not->toContain('exchange_rate')
        ->and($columns)->not->toContain('converted_amount_minor');
});

it('requires the timestamp that matches each payout status', function () {
    $payoutId = Aff::payout(Aff::affiliate(Aff::user()), Aff::policy(), amount: 15000);

    foreach (['approved', 'paid', 'rejected', 'cancelled'] as $status) {
        expect(fn () => Fx::owner()->update(
            'UPDATE affiliate_payouts SET status = ? WHERE id = ?', [$status, $payoutId],
        ))->toThrow(QueryException::class);
    }
});

it('pays a commission at most once inside a payout', function () {
    $ctx = Aff::attributableOrder();
    $attributionId = Aff::attribution($ctx['order_id'], $ctx['affiliate_id'], $ctx['code_id'], $ctx['policy_id']);
    $commissionId = Aff::commission($ctx, $attributionId, amount: 750, base: 5000);
    $payoutId = Aff::payout($ctx['affiliate_id'], $ctx['policy_id'], amount: 750);

    Aff::payoutItem($payoutId, $commissionId, 750);

    expect(fn () => Aff::payoutItem($payoutId, $commissionId, 750))->toThrow(QueryException::class);
});
