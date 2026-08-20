<?php

declare(strict_types=1);

use App\Services\Affiliate\AffiliateOperationException;
use App\Services\Affiliate\AffiliatePayoutService;
use App\Services\Affiliate\AffiliateRefundReversalService;
use App\Services\Affiliate\AffiliateRefusalReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

/**
 * P6-D4 — administrative payouts (D-069).
 *
 * Read from `pg_catalog`, never `information_schema`: the latter is filtered by privilege,
 * so a contract written against it passes on an empty list and proves nothing.
 *
 * NON-TRANSACTIONAL harness: `orders`, `payments` and `refunds` carry DEFERRABLE constraint
 * triggers that only fire at COMMIT, and `UsesAffiliateAuthority` refuses to run inside an
 * ambient transaction at all — a bounded authority owns its own transactional boundary.
 */
uses(InteractsWithCrmDatabase::class);

// ── Fixtures ─────────────────────────────────────────────────────────────────────

function p6d4Admin(string $email): int
{
    return (int) Fx::owner()->selectOne(
        "INSERT INTO users (email, password_hash, role, status, created_at, updated_at)
         VALUES (?, 'x', 'admin', 'active', now(), now()) RETURNING id",
        [$email],
    )->id;
}

/** A FRESH affiliate each call: `users.email` and `affiliate_codes.code` are both unique. */
function p6d4Affiliate(string $code): int
{
    $userId = (int) Fx::owner()->selectOne(
        "INSERT INTO users (email, password_hash, role, status, created_at, updated_at)
         VALUES (?, 'x', 'customer', 'active', now(), now()) RETURNING id",
        ['aff-'.strtolower($code).'@example.test'],
    )->id;

    $affiliateId = (int) Fx::owner()->selectOne(
        "INSERT INTO affiliates (public_id, user_id, status, applied_at, approved_at, created_at, updated_at)
         VALUES (gen_random_uuid(), ?, 'active', now(), now(), now(), now()) RETURNING id",
        [$userId],
    )->id;

    Fx::owner()->statement(
        'INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at)
         VALUES (?, ?, true, now(), now())',
        [$affiliateId, $code],
    );

    return $affiliateId;
}

function p6d4Policy(int $threshold = 1000, string $currency = 'XOF', int $version = 1): int
{
    return (int) Fx::owner()->selectOne(
        "INSERT INTO affiliate_program_policies
            (public_id, version, status, attribution_model, attribution_window_days,
             default_commission_bps, payable_delay_days, payout_threshold_minor,
             payout_currency, manual_payout_only, effective_from, created_at, updated_at)
         VALUES (gen_random_uuid(), ?, 'active', 'code_then_last_click', 30, 1500, 14, ?, ?,
                 true, now(), now(), now()) RETURNING id",
        [$version, $threshold, $currency],
    )->id;
}

/**
 * A paid, attributed, accrued and PAYABLE order. Everything upstream of P6-D4 is exercised
 * through the real authorities so the payout acts on genuine commissions.
 *
 * ⚠️ The policy is created with its FINAL values. `enforce_affiliate_policy_immutability`
 * refuses to tune an effective policy — changing a rate or a threshold means publishing a
 * new version, never rewriting the one that already explains past commissions (D-058).
 *
 * @param  list<int>  $lineTotals
 * @return array{affiliate_id: int, order_id: int, payment_id: int}
 */
function p6d4PayableOrder(array $lineTotals, int $threshold = 1000, string $payoutCurrency = 'XOF', string $code = 'D4PROBE12345'): array
{
    $affiliateId = p6d4Affiliate($code);
    p6d4Policy($threshold, $payoutCurrency);

    $visitorId = (string) Fx::owner()->selectOne(
        'INSERT INTO visitors (id, created_at, updated_at) VALUES (gen_random_uuid(), now(), now()) RETURNING id',
    )->id;

    DB::selectOne('SELECT public.record_affiliate_touch(?, NULL, ?, ?) AS s', [$visitorId, $code, 'link']);

    $total = array_sum($lineTotals);

    $orderId = (int) Fx::owner()->transaction(function () use ($visitorId, $lineTotals, $total): int {
        $id = (int) Fx::owner()->selectOne(
            "INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, visitor_id,
                                 customer_email, subtotal_minor, discount_minor, tax_minor,
                                 total_minor, currency, status, placed_at, expires_at,
                                 created_at, updated_at)
             VALUES (gen_random_uuid(),
                     'DGT-2026-' || upper(substring(replace(gen_random_uuid()::text, '-', '') FROM 1 FOR 10)),
                     md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text), ?,
                     'buyer@example.test', ?, 0, 0, ?, 'XOF', 'pending',
                     now(), now() + interval '30 minutes', now(), now())
             RETURNING id",
            [$visitorId, $total, $total],
        )->id;

        foreach ($lineTotals as $index => $lineTotal) {
            Fx::owner()->statement(
                "INSERT INTO order_items (order_id, product_id, purchased_product_id,
                                          product_name_snapshot, product_slug_snapshot,
                                          product_type_snapshot, unit_price_minor, quantity,
                                          line_subtotal_minor, line_discount_minor,
                                          line_total_minor, currency, created_at, updated_at)
                 VALUES (?, NULL, 1, ?, ?, 'ebook', ?, 1, ?, 0, ?, 'XOF', now(), now())",
                [$id, 'L'.$index, 'l-'.$index, $lineTotal, $lineTotal, $lineTotal],
            );
        }

        return $id;
    });

    $paymentId = (int) Fx::owner()->transaction(function () use ($orderId, $total): int {
        $id = (int) Fx::owner()->selectOne(
            "INSERT INTO payments (public_id, order_id, attempt_number, provider,
                                   idempotency_key_hash, amount_minor, currency, status,
                                   initiated_at, succeeded_at, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, 1, 'cinetpay',
                     md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text), ?, 'XOF',
                     'succeeded', now(), now(), now(), now()) RETURNING id",
            [$orderId, $total],
        )->id;

        Fx::owner()->statement("UPDATE orders SET status = 'paid', paid_at = now() WHERE id = ?", [$orderId]);

        return $id;
    });

    DB::selectOne('SELECT public.resolve_affiliate_attribution(?) AS s', [$orderId]);
    DB::selectOne('SELECT public.accrue_affiliate_commissions(?) AS s', [$orderId]);

    // Bring the deadline forward and promote, through the real sweep authority.
    Fx::owner()->statement("UPDATE affiliate_commissions SET payable_at = now() - interval '1 day'");
    DB::selectOne('SELECT public.promote_affiliate_commissions_to_payable(?) AS p', [100]);

    return ['affiliate_id' => $affiliateId, 'order_id' => $orderId, 'payment_id' => $paymentId];
}

/** @return list<object> commissions with their live ledger balance. */
function p6d4Commissions(): array
{
    return Fx::owner()->select(<<<'SQL'
        SELECT c.id, c.status,
               COALESCE((SELECT SUM(e.amount_minor) FROM affiliate_commission_entries e
                         WHERE e.commission_id = c.id), 0) AS balance
        FROM affiliate_commissions c ORDER BY c.id
        SQL);
}

function p6d4Request(int $affiliateId, int $actorId, string $currency = 'XOF'): object
{
    return DB::selectOne(
        'SELECT * FROM public.request_affiliate_payout(?, ?, ?)',
        [$affiliateId, $currency, $actorId],
    );
}

function p6d4Transition(int $payoutId, string $from, string $to, int $actorId, ?string $reference = null): string
{
    return (string) DB::selectOne(
        'SELECT public.transition_affiliate_payout(?, ?, ?, ?, ?) AS s',
        [$payoutId, $from, $to, $actorId, $reference],
    )->s;
}

beforeEach(function (): void {
    Config::set('affiliate.governance_enabled', true);
});

// ── 1. The frontier ──────────────────────────────────────────────────────────────

it('owns exactly migration 000034, five authorities and two idempotency indexes', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(52)
        ->and(glob($root.'/database/migrations/2026_07_14_000034*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000037*.php') ?: [])->toBe([]);

    $functions = array_map(
        static fn (object $r): string => $r->proname,
        Fx::owner()->select(<<<'SQL'
            SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%payout%' ORDER BY 1
            SQL),
    );

    expect($functions)->toBe([
        'list_affiliate_payout_candidates',
        'list_affiliate_payout_items',
        'list_affiliate_payouts',
        'request_affiliate_payout',
        'transition_affiliate_payout',
    ]);

    // The idempotency identities, at the closest point to the effect.
    $indexes = array_map(
        static fn (object $r): string => $r->indexname,
        Fx::owner()->select(<<<'SQL'
            SELECT c.relname AS indexname
            FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid
            JOIN pg_class t ON t.oid = i.indrelid
            WHERE t.relname = 'affiliate_commission_entries' AND i.indisunique
              AND c.relname LIKE '%payout%'
            ORDER BY 1
            SQL),
    );

    expect($indexes)->toBe([
        'affiliate_commission_entries_payout_allocation_once',
        'affiliate_commission_entries_payout_reversal_once',
    ]);

    // No trigger anywhere on the payout tables: P6-D4 keeps the D-067 rule.
    expect(Fx::owner()->select(<<<'SQL'
        SELECT t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
        WHERE NOT t.tgisinternal AND c.relname IN ('affiliate_payouts', 'affiliate_payout_items')
        SQL))->toBe([]);
});

// ── 2. Candidates ────────────────────────────────────────────────────────────────

it('lists the payable balance from the ledger, not from the nominal commission', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);

    // A partial refund reduces what is really owed BEFORE any payout is requested.
    $refundId = (int) Fx::owner()->transaction(function () use ($ctx): int {
        $id = (int) Fx::owner()->selectOne(
            "INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor,
                                  currency, status, requested_at, succeeded_at, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, 'cinetpay',
                     md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text), 5000, 'XOF',
                     'succeeded', now(), now(), now(), now()) RETURNING id",
            [$ctx['payment_id']],
        )->id;

        Fx::owner()->statement("UPDATE orders SET status = 'partially_refunded' WHERE id = ?", [$ctx['order_id']]);

        return $id;
    });

    app(AffiliateRefundReversalService::class)->reverseForRefund($refundId);

    $candidates = DB::select('SELECT * FROM public.list_affiliate_payout_candidates(?)', [50]);

    // 1500 accrued, 750 reversed by the half refund: 750 is what is owed.
    expect($candidates)->toHaveCount(1)
        ->and((int) $candidates[0]->payable_total_minor)->toBe(750)
        ->and((int) $candidates[0]->commission_count)->toBe(2)
        ->and($candidates[0]->is_eligible)->toBeFalse(); // below the 1000 threshold
});

it('never compares a threshold across currencies', function () {
    // The policy pays in USD from the start; the commissions are XOF. No conversion exists
    // anywhere in this repository, so the pair is simply not eligible — never converted.
    // (It is created that way because an effective policy cannot be tuned afterwards.)
    $ctx = p6d4PayableOrder([3000, 7000], payoutCurrency: 'USD');

    $candidates = DB::select('SELECT * FROM public.list_affiliate_payout_candidates(?)', [50]);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->currency)->toBe('XOF')
        ->and($candidates[0]->is_eligible)->toBeFalse();

    // And the request refuses for the same reason rather than converting anything.
    expect(p6d4Request($ctx['affiliate_id'], p6d4Admin('a1@example.test'))->payout_status)
        ->toBe('unsupported_currency');
});

// ── 3. Request ───────────────────────────────────────────────────────────────────

it('reserves every payable commission at request time, not at approval', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $admin = p6d4Admin('a1@example.test');

    $result = p6d4Request($ctx['affiliate_id'], $admin);

    expect($result->payout_status)->toBe('requested')
        ->and($result->payout_id)->not->toBeNull();

    // Reserved: two administrators drafting at once must not both grab the same commission.
    foreach (p6d4Commissions() as $commission) {
        expect($commission->status)->toBe('allocated')
            ->and((int) $commission->balance)->toBe(0);
    }

    $entries = Fx::owner()->select(
        "SELECT amount_minor, payout_id FROM affiliate_commission_entries
         WHERE entry_type = 'payout_allocation' ORDER BY id",
    );

    expect($entries)->toHaveCount(2)
        ->and((int) $entries[0]->amount_minor)->toBeLessThan(0)
        ->and((int) $entries[0]->payout_id)->toBe((int) $result->payout_id);

    $payout = Fx::owner()->selectOne('SELECT * FROM affiliate_payouts');

    expect((int) $payout->amount_minor)->toBe(1500)
        ->and((int) $payout->threshold_minor_snapshot)->toBe(1000)
        ->and((int) $payout->requested_by_user_id)->toBe($admin)
        ->and($payout->status)->toBe('requested');

    // Nothing left to reserve.
    expect(p6d4Request($ctx['affiliate_id'], $admin)->payout_status)->toBe('nothing_payable');
});

it('refuses a request below the threshold, and reserves nothing doing so', function () {
    // The threshold is set at creation: an effective policy cannot be tuned afterwards.
    $ctx = p6d4PayableOrder([3000, 7000], threshold: 100_000);
    $admin = p6d4Admin('a1@example.test');

    expect(p6d4Request($ctx['affiliate_id'], $admin)->payout_status)->toBe('below_threshold')
        ->and(Fx::owner()->select('SELECT 1 FROM affiliate_payouts'))->toBe([])
        // Nothing was reserved: the commissions are still payable.
        ->and(p6d4Commissions()[0]->status)->toBe('payable');
});

it('refuses a request with no active policy, or for an affiliate that does not exist', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $admin = p6d4Admin('a1@example.test');

    // Superseding IS allowed — it is how publication works. Only the tunables are frozen.
    Fx::owner()->statement("UPDATE affiliate_program_policies SET status = 'superseded'");

    expect(p6d4Request($ctx['affiliate_id'], $admin)->payout_status)->toBe('no_active_policy')
        ->and(p6d4Request(999_999, $admin)->payout_status)->toBe('no_such_affiliate')
        ->and(Fx::owner()->select('SELECT 1 FROM affiliate_payouts'))->toBe([]);
});

it('pays an affiliate who was suspended after earning, rather than confiscating', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $admin = p6d4Admin('a1@example.test');

    Fx::owner()->statement(
        "UPDATE affiliates SET status = 'suspended', suspended_at = now() WHERE id = ?",
        [$ctx['affiliate_id']],
    );

    // Money already earned stays owed. Refusing here would invent a confiscation policy.
    expect(p6d4Request($ctx['affiliate_id'], $admin)->payout_status)->toBe('requested');
});

// ── 4. The state machine ─────────────────────────────────────────────────────────

it('demands two distinct administrators before a payout can be approved', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $requester = p6d4Admin('a1@example.test');
    $approver = p6d4Admin('a2@example.test');

    $payoutId = (int) p6d4Request($ctx['affiliate_id'], $requester)->payout_id;

    // The compare-and-swap protects against a race between two administrators; it protects
    // nothing against one administrator approving his own transfer.
    expect(p6d4Transition($payoutId, 'requested', 'approved', $requester))->toBe('same_administrator_forbidden')
        ->and(p6d4Transition($payoutId, 'requested', 'approved', $approver))->toBe('transitioned');

    $payout = Fx::owner()->selectOne('SELECT * FROM affiliate_payouts');

    expect($payout->status)->toBe('approved')
        ->and((int) $payout->approved_by_user_id)->toBe($approver)
        ->and($payout->approved_at)->not->toBeNull();
});

it('refuses to record a payment without an administrative reference', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $requester = p6d4Admin('a1@example.test');
    $approver = p6d4Admin('a2@example.test');

    $payoutId = (int) p6d4Request($ctx['affiliate_id'], $requester)->payout_id;
    p6d4Transition($payoutId, 'requested', 'approved', $approver);

    // A domain rule, so a status — not an exception, which is reserved for a broken call
    // contract. Blank and whitespace are the same thing.
    expect(p6d4Transition($payoutId, 'approved', 'paid', $requester, null))->toBe('missing_administrative_reference')
        ->and(p6d4Transition($payoutId, 'approved', 'paid', $requester, '   '))->toBe('missing_administrative_reference')
        ->and(p6d4Transition($payoutId, 'approved', 'paid', $requester, ' BORD-2026-114 '))->toBe('transitioned');

    $payout = Fx::owner()->selectOne('SELECT * FROM affiliate_payouts');

    expect($payout->status)->toBe('paid')
        ->and($payout->administrative_reference)->toBe('BORD-2026-114')
        ->and($payout->paid_at)->not->toBeNull();

    foreach (p6d4Commissions() as $commission) {
        expect($commission->status)->toBe('paid');
    }
});

it('allows only the arbitrated transitions and makes paid terminal', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $requester = p6d4Admin('a1@example.test');
    $approver = p6d4Admin('a2@example.test');

    $payoutId = (int) p6d4Request($ctx['affiliate_id'], $requester)->payout_id;

    // `requested` cannot jump straight to paid.
    expect(p6d4Transition($payoutId, 'requested', 'paid', $approver, 'REF'))->toBe('illegal_transition');

    p6d4Transition($payoutId, 'requested', 'approved', $approver);

    // `approved` cannot be rejected — only paid or cancelled.
    expect(p6d4Transition($payoutId, 'approved', 'rejected', $approver))->toBe('illegal_transition');

    p6d4Transition($payoutId, 'approved', 'paid', $requester, 'REF');

    // ⚠️ `paid` is TERMINAL. This is what keeps `payout_reversal` scoped to reservations
    // that were never paid; clawing back a real transfer is a different gate.
    foreach (['cancelled', 'rejected', 'approved'] as $target) {
        expect(p6d4Transition($payoutId, 'paid', $target, $approver, 'REF'))->toBe('illegal_transition');
    }

    expect(p6d4Transition(999_999, 'requested', 'approved', $approver))->toBe('no_such_payout');
});

it('refuses a decision taken on a stale screen instead of overwriting a colleague', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $requester = p6d4Admin('a1@example.test');
    $approver = p6d4Admin('a2@example.test');

    $payoutId = (int) p6d4Request($ctx['affiliate_id'], $requester)->payout_id;
    p6d4Transition($payoutId, 'requested', 'approved', $approver);

    // A second administrator still looking at `requested` decides. The database refuses.
    expect(fn () => p6d4Transition($payoutId, 'requested', 'cancelled', $requester))
        ->toThrow(QueryException::class);

    expect(Fx::owner()->selectOne('SELECT status FROM affiliate_payouts')->status)->toBe('approved');
});

// ── 5. Release — the reservation that was never paid ─────────────────────────────

it('releases the reservation when a payout is cancelled, in the same transaction', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $requester = p6d4Admin('a1@example.test');

    $payoutId = (int) p6d4Request($ctx['affiliate_id'], $requester)->payout_id;

    expect(p6d4Transition($payoutId, 'requested', 'cancelled', $requester))->toBe('transitioned');

    // The amount comes from the allocation entry itself, never recomputed: a recomputation
    // could diverge from what was actually withdrawn.
    $reversals = Fx::owner()->select(
        "SELECT amount_minor, payout_id FROM affiliate_commission_entries
         WHERE entry_type = 'payout_reversal' ORDER BY id",
    );

    expect($reversals)->toHaveCount(2)
        ->and((int) $reversals[0]->amount_minor)->toBe(450)
        ->and((int) $reversals[1]->amount_minor)->toBe(1050)
        ->and((int) $reversals[0]->payout_id)->toBe($payoutId);

    // Back to payable, with the balance restored — and requestable again.
    foreach (p6d4Commissions() as $commission) {
        expect($commission->status)->toBe('payable');
    }

    expect(array_sum(array_map(static fn (object $c): int => (int) $c->balance, p6d4Commissions())))->toBe(1500)
        ->and(p6d4Request($ctx['affiliate_id'], $requester)->payout_status)->toBe('requested');
});

it('releases the reservation on rejection too', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $requester = p6d4Admin('a1@example.test');

    $payoutId = (int) p6d4Request($ctx['affiliate_id'], $requester)->payout_id;

    expect(p6d4Transition($payoutId, 'requested', 'rejected', $requester))->toBe('transitioned');

    foreach (p6d4Commissions() as $commission) {
        expect($commission->status)->toBe('payable');
    }
});

it('lets the storage layer refuse a replayed allocation or release', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $requester = p6d4Admin('a1@example.test');

    $payoutId = (int) p6d4Request($ctx['affiliate_id'], $requester)->payout_id;
    $commissionId = (int) p6d4Commissions()[0]->id;

    $forge = static fn (string $type, int $amount): callable => static fn () => Fx::owner()->statement(
        'INSERT INTO affiliate_commission_entries (affiliate_id, commission_id, entry_type,
             amount_minor, currency, payout_id, occurred_at, created_at)
         SELECT affiliate_id, ?, ?, ?, currency, ?, now(), now()
         FROM affiliate_commissions WHERE id = ?',
        [$commissionId, $type, $amount, $payoutId, $commissionId],
    );

    // The request already wrote the allocation, so a SECOND one is refused straight away —
    // forged by the OWNER, the strongest caller there is.
    expect($forge('payout_allocation', -1))->toThrow(QueryException::class);

    // No reversal exists yet: the first is accepted, the replay is not.
    $forge('payout_reversal', 1)();

    expect($forge('payout_reversal', 1))->toThrow(QueryException::class);
});

// ── 6. D-057 §10 — the hole P6-D3 could not yet meet ─────────────────────────────

it('carries a negative balance when a PAID commission is refunded', function () {
    $ctx = p6d4PayableOrder([10_000]);
    $requester = p6d4Admin('a1@example.test');
    $approver = p6d4Admin('a2@example.test');

    $payoutId = (int) p6d4Request($ctx['affiliate_id'], $requester)->payout_id;
    p6d4Transition($payoutId, 'requested', 'approved', $approver);
    p6d4Transition($payoutId, 'approved', 'paid', $requester, 'BORD-1');

    expect(p6d4Commissions()[0]->status)->toBe('paid')
        // Accrual +1500, allocation −1500.
        ->and((int) p6d4Commissions()[0]->balance)->toBe(0);

    $refundId = (int) Fx::owner()->transaction(function () use ($ctx): int {
        $id = (int) Fx::owner()->selectOne(
            "INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor,
                                  currency, status, requested_at, succeeded_at, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, 'cinetpay',
                     md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text), 10000, 'XOF',
                     'succeeded', now(), now(), now(), now()) RETURNING id",
            [$ctx['payment_id']],
        )->id;

        Fx::owner()->statement("UPDATE orders SET status = 'refunded' WHERE id = ?", [$ctx['order_id']]);

        return $id;
    });

    expect(app(AffiliateRefundReversalService::class)->reverseForRefund($refundId))->toBe('reversed');

    $commission = p6d4Commissions()[0];

    // D-057 §10, at last enforced: "commission déjà payée ⇒ solde négatif reporté et
    // auditable". Before P6-D4 the cap on the ledger balance silently reduced this to zero
    // and the refunded commission stayed with the affiliate.
    expect((int) $commission->balance)->toBe(-1500)
        // Still `paid`: it WAS paid, and pretending otherwise would erase the very fact the
        // ledger exists to record.
        ->and($commission->status)->toBe('paid');
});

// ── 7. The privilege frontier ────────────────────────────────────────────────────

it('gives the runtime EXECUTE on the five authorities and nothing else', function () {
    $signatures = [
        'public.list_affiliate_payout_candidates(integer)',
        'public.request_affiliate_payout(bigint, character varying, bigint)',
        'public.list_affiliate_payouts(character varying, integer)',
        'public.list_affiliate_payout_items(bigint)',
        'public.transition_affiliate_payout(bigint, character varying, character varying, bigint, character varying)',
    ];

    foreach ($signatures as $signature) {
        expect(DB::selectOne("SELECT has_function_privilege(current_user, '{$signature}', 'EXECUTE') AS v")->v)->toBeTrue()
            ->and(DB::selectOne("SELECT has_function_privilege('public', '{$signature}', 'EXECUTE') AS v")->v)->toBeFalse();
    }

    $meta = Fx::owner()->select(<<<'SQL'
        SELECT p.proname, pg_get_userbyid(p.proowner) AS owner, p.prosecdef,
               array_to_string(p.proconfig, ',') AS cfg
        FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.proname LIKE '%payout%'
        SQL);

    expect($meta)->toHaveCount(5);

    foreach ($meta as $row) {
        expect($row->owner)->toBe('digitrove_affiliate_executor')
            ->and($row->prosecdef)->toBeTrue()
            ->and($row->cfg)->toBe('search_path=pg_catalog, public, pg_temp');
    }

    foreach (['affiliate_payouts', 'affiliate_payout_items'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect(DB::selectOne("SELECT has_table_privilege(current_user, '{$table}', '{$privilege}') AS v")->v)
                ->toBeFalse("runtime must not hold {$privilege} on {$table}");
        }
    }
});

it('adds no commerce column beyond what P6-D2 and P6-D3 already granted', function () {
    $granted = [];

    foreach (['orders', 'order_items', 'refunds', 'payments', 'users'] as $table) {
        $granted[$table] = array_map(
            static fn (object $r): string => $r->column_name,
            Fx::owner()->select(<<<'SQL'
                SELECT a.attname AS column_name
                FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = ? AND a.attnum > 0 AND NOT a.attisdropped
                  AND has_column_privilege('digitrove_affiliate_executor', c.oid, a.attnum, 'SELECT')
                ORDER BY 1
                SQL, [$table]),
        );
    }

    // The CUMULATIVE surface, not this gate's delta. P6-D4 needed nothing new: payouts,
    // items, commissions, ledger and policies all live inside the affiliate block, and the
    // administrator's identity is checked through `assert_affiliate_actor_is_admin`.
    // ⚠️ `users.email` is NOT here and must not be: affiliates are identified by
    // `public_id`, exactly like the other two affiliate screens.
    expect($granted)->toBe([
        'orders' => ['id', 'paid_at', 'placed_at', 'status', 'user_id', 'visitor_id'],
        'order_items' => ['currency', 'id', 'line_total_minor', 'order_id'],
        'refunds' => ['id', 'payment_id', 'status'],
        'payments' => ['id', 'order_id'],
        'users' => ['deleted_at', 'id', 'role', 'status'],
    ]);
});

// ── 8. The service layer ─────────────────────────────────────────────────────────

it('translates a stale payout decision into its own refusal, never the code-rotation one', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $requester = p6d4Admin('a1@example.test');
    $approver = p6d4Admin('a2@example.test');

    $service = app(AffiliatePayoutService::class);
    $payoutId = (int) $service->request($ctx['affiliate_id'], 'XOF', $requester)['payout_id'];

    $service->transition($payoutId, 'requested', 'approved', $approver);

    try {
        $service->transition($payoutId, 'requested', 'cancelled', $requester);
        $reason = null;
    } catch (AffiliateOperationException $exception) {
        $reason = $exception->reason;
    }

    // `AF002`, not `AF001`: an administrator deciding on money must not be told about
    // affiliate codes.
    expect($reason)->toBe(AffiliateRefusalReason::StalePayoutTransition);
});

it('refuses every payout operation when the affiliate surface is switched off', function () {
    $ctx = p6d4PayableOrder([3000, 7000]);
    $admin = p6d4Admin('a1@example.test');

    Config::set('affiliate.governance_enabled', false);

    $service = app(AffiliatePayoutService::class);

    expect(fn () => $service->candidates())->toThrow(AffiliateOperationException::class)
        ->and(fn () => $service->request($ctx['affiliate_id'], 'XOF', $admin))->toThrow(AffiliateOperationException::class)
        ->and(Fx::owner()->select('SELECT 1 FROM affiliate_payouts'))->toBe([]);
});
