<?php

declare(strict_types=1);

use App\Events\OrderPaid;
use App\Jobs\ProcessAffiliateAttribution;
use App\Listeners\ResolveAffiliateAttribution;
use App\Services\Affiliate\AffiliateAttributionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

/**
 * P6-D2 — touch capture and order attribution (D-067).
 *
 * Everything is read from `pg_catalog`, never `information_schema`: the latter is filtered
 * by privilege, so a contract written against it passes on an empty list and proves nothing.
 *
 * The harness is the NON-TRANSACTIONAL one on purpose. Several proofs here need a real
 * COMMIT — the deferred CHECK constraints on `orders` only fire then — and one needs two
 * genuinely independent transactions. See `P6D2GuestCheckoutSequenceTest` for the full
 * write-up of why `RefreshDatabase` cannot serve that.
 */
uses(InteractsWithCrmDatabase::class);

// ── Fixtures ─────────────────────────────────────────────────────────────────────

function p6d2Affiliate(string $code = 'PROBE1234ABCD', string $status = 'active'): array
{
    $userId = (int) Fx::owner()->selectOne(
        "INSERT INTO users (email, password_hash, role, status, created_at, updated_at)
         VALUES (?, 'x', 'customer', 'active', now(), now()) RETURNING id",
        ['aff-'.strtolower($code).'@example.test'],
    )->id;

    // `affiliates_status_timestamps_check` demands the marker that matches the status:
    // 'suspended' needs `suspended_at`, 'closed' needs `closed_at`, and so on.
    $affiliateId = (int) Fx::owner()->selectOne(<<<'SQL'
        INSERT INTO affiliates (public_id, user_id, status, applied_at, approved_at,
                                suspended_at, rejected_at, closed_at, created_at, updated_at)
        VALUES (gen_random_uuid(), ?, ?, now(), now(),
                CASE WHEN ? = 'suspended' THEN now() END,
                CASE WHEN ? = 'rejected'  THEN now() END,
                CASE WHEN ? = 'closed'    THEN now() END,
                now(), now())
        RETURNING id
        SQL, [$userId, $status, $status, $status, $status])->id;

    $codeId = (int) Fx::owner()->selectOne(
        'INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at)
         VALUES (?, ?, true, now(), now()) RETURNING id',
        [$affiliateId, $code],
    )->id;

    return ['affiliate_id' => $affiliateId, 'code_id' => $codeId, 'code' => $code];
}

function p6d2Policy(int $windowDays = 30, int $version = 1): int
{
    return (int) Fx::owner()->selectOne(
        "INSERT INTO affiliate_program_policies
            (public_id, version, status, attribution_model, attribution_window_days,
             default_commission_bps, payable_delay_days, payout_threshold_minor,
             payout_currency, manual_payout_only, effective_from, created_at, updated_at)
         VALUES (gen_random_uuid(), ?, 'active', 'code_then_last_click', ?,
                 1500, 14, 10000, 'XOF', true, now(), now(), now()) RETURNING id",
        [$version, $windowDays],
    )->id;
}

function p6d2Visitor(string $uuid): string
{
    Fx::owner()->statement('INSERT INTO visitors (id, created_at, updated_at) VALUES (?, now(), now())', [$uuid]);

    return $uuid;
}

/**
 * An order and its line in ONE transaction: `orders` carries DEFERRABLE checks that only
 * fire at COMMIT, so a factory followed by a separate insert would fail with 23514.
 */
function p6d2Order(?string $visitorId = null, ?int $userId = null, ?string $placedAt = null): int
{
    return (int) Fx::owner()->transaction(function () use ($visitorId, $userId, $placedAt): int {
        $orderId = (int) Fx::owner()->selectOne(
            "INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, visitor_id, user_id,
                                 customer_email, subtotal_minor, discount_minor, tax_minor, total_minor,
                                 currency, status, placed_at, expires_at, created_at, updated_at)
             VALUES (gen_random_uuid(),
                     'DGT-2026-' || upper(substring(replace(gen_random_uuid()::text, '-', '') FROM 1 FOR 10)),
                     -- 64 hex chars for `orders_checkout_idempotency_hash_format_check`.
                     -- `gen_random_bytes` lives in pgcrypto, which this cluster does not load;
                     -- `gen_random_uuid` is native since PostgreSQL 13.
                     md5(gen_random_uuid()::text) || md5(gen_random_uuid()::text), ?, ?, 'buyer@example.test',
                     5000, 0, 0, 5000, 'XOF', 'pending',
                     -- `orders_expiration_after_placement_check`: the window is measured
                     -- FROM the placement, so a back- or forward-dated order must carry its
                     -- own expiry, not one anchored to now().
                     COALESCE(?::timestamptz, now()),
                     COALESCE(?::timestamptz, now()) + interval '30 minutes', now(), now())
             RETURNING id",
            [$visitorId, $userId, $placedAt, $placedAt],
        )->id;

        Fx::owner()->statement(
            "INSERT INTO order_items (order_id, product_id, purchased_product_id, product_name_snapshot,
                                      product_slug_snapshot, product_type_snapshot, unit_price_minor,
                                      quantity, line_subtotal_minor, line_discount_minor, line_total_minor,
                                      currency, created_at, updated_at)
             VALUES (?, NULL, 1, 'Probe', 'probe', 'ebook', 5000, 1, 5000, 0, 5000, 'XOF', now(), now())",
            [$orderId],
        );

        return $orderId;
    });
}

/** Calls an authority under the REAL runtime identity, never the owner. */
function p6d2Touch(?string $visitorId, ?int $userId, string $code, string $source): string
{
    return (string) DB::selectOne(
        'SELECT public.record_affiliate_touch(?, ?, ?, ?) AS status',
        [$visitorId, $userId, $code, $source],
    )->status;
}

function p6d2Resolve(int $orderId): string
{
    return (string) DB::selectOne('SELECT public.resolve_affiliate_attribution(?) AS status', [$orderId])->status;
}

beforeEach(function (): void {
    Config::set('affiliate.governance_enabled', true);
});

// ── 1. The frontier ──────────────────────────────────────────────────────────────

it('owns exactly migration 000032 and installs its two authorities, and no trigger', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(49)
        ->and(glob($root.'/database/migrations/2026_07_14_000032*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000034*.php') ?: [])->toBe([]);

    // `prokind = 'f'`: an ordinary function. A trigger function would return `trigger`.
    $functions = array_map(
        static fn (object $r): string => $r->proname.'|'.$r->result,
        Fx::owner()->select(<<<'SQL'
            SELECT p.proname, pg_get_function_result(p.oid) AS result
            FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public'
              AND p.proname IN ('record_affiliate_touch', 'resolve_affiliate_attribution')
            ORDER BY 1
            SQL),
    );

    expect($functions)->toBe([
        'record_affiliate_touch|text',
        'resolve_affiliate_attribution|text',
    ]);
});

// ── 2. Touch capture ─────────────────────────────────────────────────────────────

it('records a touch, normalises the code, and refuses to duplicate a live one', function () {
    p6d2Affiliate();
    p6d2Policy();
    $visitor = p6d2Visitor('11111111-1111-4111-8111-111111111111');

    // Case and surrounding blanks are normalised on the PARAMETER, never on the column.
    expect(p6d2Touch($visitor, null, '  probe1234abcd ', 'link'))->toBe('recorded')
        // Same identity, same code, same source, still live: no second row.
        ->and(p6d2Touch($visitor, null, 'PROBE1234ABCD', 'link'))->toBe('already_active')
        // A different source is a different fact and IS recorded.
        ->and(p6d2Touch($visitor, null, 'PROBE1234ABCD', 'code'))->toBe('recorded');

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_touches')->c)->toBe(2);
});

it('stays silent about unknown codes, inactive affiliates and a dormant programme', function () {
    $visitor = p6d2Visitor('11111111-1111-4111-8111-111111111111');
    p6d2Affiliate('SUSPENDED123', 'suspended');

    // No policy yet: a real code still records nothing.
    p6d2Affiliate();
    expect(p6d2Touch($visitor, null, 'PROBE1234ABCD', 'link'))->toBe('no_active_policy');

    p6d2Policy();

    expect(p6d2Touch($visitor, null, 'NOSUCHCODE99', 'link'))->toBe('no_such_code')
        // An affiliate who is not active is indistinguishable from a code that does not exist.
        ->and(p6d2Touch($visitor, null, 'SUSPENDED123', 'link'))->toBe('no_such_code');

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_touches')->c)->toBe(0);
});

it('refuses a touch with no subject and an invalid source', function () {
    p6d2Affiliate();
    p6d2Policy();

    expect(fn () => p6d2Touch(null, null, 'PROBE1234ABCD', 'link'))
        ->toThrow(QueryException::class)
        ->and(fn () => p6d2Touch('11111111-1111-4111-8111-111111111111', null, 'PROBE1234ABCD', 'banner'))
        ->toThrow(QueryException::class);
});

// ── 3. Attribution ───────────────────────────────────────────────────────────────

it('attributes a paid order to the touch, once, and reports a replay honestly', function () {
    $aff = p6d2Affiliate();
    $policy = p6d2Policy();
    $visitor = p6d2Visitor('11111111-1111-4111-8111-111111111111');

    p6d2Touch($visitor, null, 'PROBE1234ABCD', 'link');
    $orderId = p6d2Order($visitor);

    expect(p6d2Resolve($orderId))->toBe('attributed')
        ->and(p6d2Resolve($orderId))->toBe('already_attributed');

    $row = Fx::owner()->selectOne('SELECT * FROM affiliate_attributions WHERE order_id = ?', [$orderId]);

    expect((int) $row->affiliate_id)->toBe($aff['affiliate_id'])
        ->and((int) $row->affiliate_code_id)->toBe($aff['code_id'])
        ->and((int) $row->policy_id)->toBe($policy)
        // A click-matched row must name the touch it came from.
        ->and($row->matched_by)->toBe('last_click')
        ->and($row->affiliate_touch_id)->not->toBeNull();
});

it('prefers an explicitly typed code over any click, and then needs no touch id', function () {
    p6d2Affiliate();
    p6d2Policy();
    $visitor = p6d2Visitor('11111111-1111-4111-8111-111111111111');

    // The click happens LAST, so pure recency would pick it. The arbitrated model does not.
    p6d2Touch($visitor, null, 'PROBE1234ABCD', 'code');
    p6d2Touch($visitor, null, 'PROBE1234ABCD', 'link');

    $orderId = p6d2Order($visitor);
    expect(p6d2Resolve($orderId))->toBe('attributed');

    $row = Fx::owner()->selectOne('SELECT * FROM affiliate_attributions WHERE order_id = ?', [$orderId]);

    // The code is its own evidence, so the touch id is deliberately left NULL.
    expect($row->matched_by)->toBe('code')
        ->and($row->affiliate_touch_id)->toBeNull();
});

it('matches on the account identity when the buyer is signed in', function () {
    p6d2Affiliate();
    p6d2Policy();

    $userId = (int) Fx::owner()->selectOne(
        "INSERT INTO users (email, password_hash, role, status, email_verified_at, created_at, updated_at)
         VALUES ('buyer@example.test', 'x', 'customer', 'active', now(), now(), now()) RETURNING id",
    )->id;

    p6d2Touch(null, $userId, 'PROBE1234ABCD', 'link');
    $orderId = p6d2Order(null, $userId);

    expect(p6d2Resolve($orderId))->toBe('attributed');
});

it('refuses to attribute outside the window, before the touch, or with no programme', function () {
    p6d2Affiliate();
    $policy = p6d2Policy(windowDays: 1);
    $visitor = p6d2Visitor('11111111-1111-4111-8111-111111111111');

    p6d2Touch($visitor, null, 'PROBE1234ABCD', 'link');

    // The order is placed two days later: past a one-day window.
    $late = p6d2Order($visitor, null, (string) now()->addDays(2));
    expect(p6d2Resolve($late))->toBe('no_match');

    // And an order placed BEFORE the touch is never retro-credited.
    $early = p6d2Order($visitor, null, (string) now()->subDay());
    expect(p6d2Resolve($early))->toBe('no_match');

    expect(p6d2Resolve(999_999))->toBe('no_such_order');

    Fx::owner()->statement("UPDATE affiliate_program_policies SET status = 'superseded' WHERE id = ?", [$policy]);
    $orphan = p6d2Order($visitor);
    expect(p6d2Resolve($orphan))->toBe('no_active_policy');
});

it('never credits an affiliate who stopped being active after the touch', function () {
    $aff = p6d2Affiliate();
    p6d2Policy();
    $visitor = p6d2Visitor('11111111-1111-4111-8111-111111111111');

    p6d2Touch($visitor, null, 'PROBE1234ABCD', 'link');
    Fx::owner()->statement("UPDATE affiliates SET status = 'closed', closed_at = now() WHERE id = ?", [$aff['affiliate_id']]);

    expect(p6d2Resolve(p6d2Order($visitor)))->toBe('no_match');
});

// ── 4. The privilege frontier ────────────────────────────────────────────────────

it('gives the runtime EXECUTE and nothing else, and PUBLIC nothing at all', function () {
    $signatures = [
        'public.record_affiliate_touch(uuid, bigint, character varying, character varying)',
        'public.resolve_affiliate_attribution(bigint)',
    ];

    foreach ($signatures as $signature) {
        expect(DB::selectOne("SELECT has_function_privilege(current_user, '{$signature}', 'EXECUTE') AS v")->v)->toBeTrue()
            ->and(DB::selectOne("SELECT has_function_privilege('public', '{$signature}', 'EXECUTE') AS v")->v)->toBeFalse();
    }

    // Owned by the non-superuser executor, SECURITY DEFINER, search_path pinned.
    $meta = Fx::owner()->select(<<<'SQL'
        SELECT p.proname, pg_get_userbyid(p.proowner) AS owner, p.prosecdef, array_to_string(p.proconfig, ',') AS cfg
        FROM pg_proc AS p JOIN pg_namespace AS n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public'
          AND p.proname IN ('record_affiliate_touch', 'resolve_affiliate_attribution')
        ORDER BY 1
        SQL);

    foreach ($meta as $row) {
        expect($row->owner)->toBe('digitrove_affiliate_executor')
            ->and($row->prosecdef)->toBeTrue()
            ->and($row->cfg)->toBe('search_path=pg_catalog, public, pg_temp');
    }

    // The authorities are the ONLY door: no direct write, no direct read.
    foreach (['affiliate_touches', 'affiliate_attributions'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect(DB::selectOne("SELECT has_table_privilege(current_user, '{$table}', '{$privilege}') AS v")->v)
                ->toBeFalse("runtime must not hold {$privilege} on {$table}");
        }
    }
});

it('lets the executor read exactly four columns of orders and nothing more', function () {
    $granted = array_map(
        static fn (object $r): string => $r->column_name,
        Fx::owner()->select(<<<'SQL'
            SELECT a.attname AS column_name
            FROM pg_attribute AS a
            JOIN pg_class AS c ON c.oid = a.attrelid
            JOIN pg_namespace AS n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = 'orders' AND a.attnum > 0 AND NOT a.attisdropped
              AND has_column_privilege('digitrove_affiliate_executor', c.oid, a.attnum, 'SELECT')
            ORDER BY 1
            SQL),
    );

    // Never the customer e-mail, never an amount. P6-D2 granted four columns; P6-D3 added
    // `paid_at` (the anchor of every commission deadline) and `status` (full vs partial
    // refund), each named in its own migration and revoked by its own `down()`.
    expect($granted)->toBe(['id', 'paid_at', 'placed_at', 'status', 'user_id', 'visitor_id']);
});

// ── 5. The application layer ─────────────────────────────────────────────────────

it('dispatches an order-id-only job from OrderPaid, and nothing when the programme is off', function () {
    Queue::fake();

    (new ResolveAffiliateAttribution)->handle(new OrderPaid(4242));

    Queue::assertPushed(ProcessAffiliateAttribution::class, function (ProcessAffiliateAttribution $job): bool {
        return $job->orderId === 4242;
    });

    // The payload is the id and ONLY the id: the constructor takes one integer, so no
    // visitor, code, affiliate or model can reach the queue even by accident.
    $parameters = (new ReflectionClass(ProcessAffiliateAttribution::class))->getConstructor()->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getName())->toBe('orderId')
        ->and((string) $parameters[0]->getType())->toBe('int');

    Config::set('affiliate.governance_enabled', false);
    (new ResolveAffiliateAttribution)->handle(new OrderPaid(4243));

    Queue::assertPushed(ProcessAffiliateAttribution::class, 1);
});

it('keeps the kill switch effective on a job that was already queued', function () {
    p6d2Affiliate();
    p6d2Policy();
    $visitor = p6d2Visitor('11111111-1111-4111-8111-111111111111');
    p6d2Touch($visitor, null, 'PROBE1234ABCD', 'link');
    $orderId = p6d2Order($visitor);

    // The flag goes off between dispatch and execution: the worker must refuse.
    Config::set('affiliate.governance_enabled', false);

    expect(app(AffiliateAttributionService::class)->resolveForOrder($orderId))->toBe('disabled')
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_attributions')->c)->toBe(0);

    Config::set('affiliate.governance_enabled', true);
    expect(app(AffiliateAttributionService::class)->resolveForOrder($orderId))->toBe('attributed');
});

it('bounds the job like every other id-only job in the repository', function () {
    $job = new ProcessAffiliateAttribution(7);

    expect($job->uniqueId())->toBe('7')
        ->and($job->tries)->toBe(5)
        ->and($job->uniqueFor)->toBe(3600)
        ->and($job->backoff())->toBe([30, 120, 300])
        // tries × (timeout + max backoff) must stay under uniqueFor, or a retry could
        // outlive the uniqueness lock and let a second job start for the same order.
        ->and($job->tries * ($job->timeout + max($job->backoff())))->toBeLessThan($job->uniqueFor);
});

// ── 6. The public capture surface ────────────────────────────────────────────────

it('answers a referral link identically whether the code exists or not', function () {
    p6d2Affiliate();
    p6d2Policy();

    $known = $this->get('/r/PROBE1234ABCD');
    $unknown = $this->get('/r/NOSUCHCODE99');

    // Same status, same destination: the endpoint is never an oracle for which codes exist.
    expect($known->status())->toBe($unknown->status())
        ->and($known->headers->get('Location'))->toBe($unknown->headers->get('Location'));

    // Only the known one actually recorded anything.
    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_touches')->c)->toBe(1);
});

it('refuses a protocol-relative destination, which would be an open redirect', function () {
    p6d2Affiliate();
    p6d2Policy();

    // `//evil.test` and `/\evil.test` both start with a slash, and browsers read them as
    // absolute URLs on another host. A referral link must never become one.
    foreach (['//evil.test', '/\evil.test', 'https://evil.test'] as $hostile) {
        $response = $this->get('/r/PROBE1234ABCD?dest='.urlencode($hostile));

        expect($response->headers->get('Location'))->toBe(route('storefront.home'));
    }

    // An internal path is still honoured. `Location` carries the absolute form, which is
    // exactly the point: the host is OURS, never one the query string chose.
    expect($this->get('/r/PROBE1234ABCD?dest=%2Fproducts')->headers->get('Location'))->toBe(url('/products'));
});

it('rejects a code that does not match the stored format before touching the database', function () {
    p6d2Affiliate();
    p6d2Policy();

    // Too short, and containing characters `affiliate_codes_format_check` forbids.
    $this->post(route('affiliate.code.store'), ['code' => 'AB'])->assertSessionHasErrors('code');
    $this->post(route('affiliate.code.store'), ['code' => 'PROBE_1234'])->assertSessionHasErrors('code');

    // The route itself refuses a malformed code in the path, before the controller runs.
    $this->get('/r/AB')->assertNotFound();

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_touches')->c)->toBe(0);
});
