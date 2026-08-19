<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/** The nine tables P6-D0 owns, in creation order. */
function p6d0Tables(): array
{
    return [
        'affiliate_program_policies', 'affiliates', 'affiliate_codes', 'affiliate_touches',
        'affiliate_attributions', 'affiliate_commissions', 'affiliate_commission_entries',
        'affiliate_payouts', 'affiliate_payout_items',
    ];
}

// À VALIDER (P6-D2): the P6-D2 boundary (000032) has landed, so the frontier moved from 47
// to 48. Teeth preserved: 000029 to 000032 must each be present exactly once, and no 000034
// may appear early.
it('sits behind the P6-D2 boundary: 49 migrations, 000029 to 000033 present, no 000034', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(49)
        ->and(glob($root.'/database/migrations/2026_07_14_000029*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000030*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000031*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000032*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000034*.php') ?: [])->toBe([]);
});

it('creates the nine affiliate tables and nothing else', function () {
    foreach (p6d0Tables() as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table: {$table}");
    }

    // P6-D1+ surfaces must not appear early.
    foreach (['affiliate_clicks', 'affiliate_balances', 'affiliate_bank_accounts', 'referrals'] as $premature) {
        expect(Schema::hasTable($premature))->toBeFalse("premature table: {$premature}");
    }
});

/**
 * Money is BIGINT minor units, rates are INTEGER basis points. A float anywhere on this
 * path would silently corrupt a payout.
 */
it('stores every amount as bigint and every rate as integer, with no float', function () {
    $numeric = Fx::owner()->select(<<<'SQL'
        SELECT c.table_name, c.column_name, c.data_type
        FROM information_schema.columns AS c
        WHERE c.table_schema = 'public'
          AND c.table_name LIKE 'affiliate%'
          AND (c.column_name LIKE '%_minor%' OR c.column_name LIKE '%_bps%'
               OR c.column_name LIKE '%_days' OR c.column_name = 'version')
        SQL);

    expect($numeric)->not->toBeEmpty();

    foreach ($numeric as $column) {
        $expected = str_contains($column->column_name, '_minor') ? 'bigint' : 'integer';

        expect($column->data_type)->toBe(
            $expected,
            "{$column->table_name}.{$column->column_name} must be {$expected}",
        );
    }

    // No approximate type anywhere in the affiliate schema.
    $floats = Fx::owner()->select(<<<'SQL'
        SELECT table_name, column_name, data_type
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name LIKE 'affiliate%'
          AND data_type IN ('real', 'double precision', 'numeric', 'money')
        SQL);

    expect($floats)->toBe([]);
});

it('carries no bank, mobile money or PII column', function () {
    $columns = Fx::owner()->select(<<<'SQL'
        SELECT table_name, column_name FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name LIKE 'affiliate%'
        SQL);

    $forbidden = ['email', 'phone', 'msisdn', 'iban', 'bank', 'wave', 'mobile_money', 'account_number', 'first_name', 'last_name'];

    foreach ($columns as $column) {
        foreach ($forbidden as $token) {
            expect(str_contains($column->column_name, $token))
                ->toBeFalse("{$column->table_name}.{$column->column_name} looks like PII or a payment credential");
        }
    }
});

it('denies the runtime any direct write on the affiliate schema', function () {
    foreach (p6d0Tables() as $table) {
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            expect((bool) Fx::owner()->selectOne(
                'SELECT has_table_privilege(?, ?, ?) AS granted',
                ['digitrove_runtime', 'public.'.$table, $privilege],
            )->granted)->toBeFalse("runtime must not hold {$privilege} on {$table}");
        }

        expect((bool) Fx::owner()->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS granted',
            ['public', 'public.'.$table, 'SELECT'],
        )->granted)->toBeFalse("PUBLIC must not read {$table}");
    }
});

// À VALIDER (P6-D1, D-058): P6-D1 legitimately adds the restricted executor role and the
// five bounded authorities. Rescoped to an EXACT inventory rather than an absence, so a
// SECOND affiliate role or an UNEXPECTED affiliate function still fails here — the teeth are
// kept, the scope moved.
it('provisions exactly the affiliate executor role, the three D0 guards and the five D1 authorities', function () {
    $roles = array_map(
        static fn (object $r): string => (string) $r->rolname,
        Fx::owner()->select("SELECT rolname FROM pg_roles WHERE rolname LIKE '%affiliate%' ORDER BY 1"),
    );

    expect($roles)->toBe(['digitrove_affiliate_executor']);

    // CURRENT-STATE, by exact name. Three structural INTEGRITY guards (D0), five bounded
    // policy authorities (D1), then D-059's lifecycle set: a fourth integrity guard, two
    // internal helpers the runtime may never execute, and ten bounded authorities — plus
    // P6-D2's two: `record_affiliate_touch` and `resolve_affiliate_attribution`.
    // Nothing else may carry an affiliate name — a ghost overload surviving a
    // `migrate:fresh` shows up here, which is how one was caught during D1.1.
    $functions = array_map(
        static fn (object $r): string => (string) $r->proname,
        Fx::owner()->select(<<<'SQL'
            SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate%' ORDER BY 1
            SQL),
    );

    expect($functions)->toBe([
        // À VALIDER (P6-D3) — the three commission authorities. Ordinary functions the
        // runtime EXECUTEs; they own no privilege on the tables themselves.
        'accrue_affiliate_commissions',
        'apply_affiliate_refund_reversal',
        'assert_affiliate_actor_is_admin',
        'close_affiliate',
        'create_affiliate_program_policy_draft',
        'current_affiliate_program_policy',
        'enforce_affiliate_ledger_append_only',
        'enforce_affiliate_lifecycle_append_only',
        'enforce_affiliate_policy_immutability',
        'enforce_affiliate_touch_subject',
        'generate_affiliate_code',
        'get_affiliate',
        'list_affiliate_codes',
        'list_affiliate_lifecycle_events',
        'list_affiliate_program_policies',
        'list_affiliates',
        'promote_affiliate_commissions_to_payable',
        'publish_affiliate_program_policy',
        'reactivate_affiliate',
        'record_affiliate_touch',
        'resolve_affiliate_attribution',
        'review_affiliate_application',
        'rotate_affiliate_code',
        'submit_affiliate_application',
        'suspend_affiliate',
        'update_affiliate_program_policy_draft',
    ]);

    // Signatures, not merely names: exactly one rotation entry point, taking the
    // compare-and-swap token. A two-argument overload would silently accept a rotation
    // with no expected code and defeat the whole mechanism.
    $signatures = array_map(
        static fn (object $r): string => (string) $r->signature,
        Fx::owner()->select(<<<'SQL'
            SELECT p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')' AS signature
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname IN ('rotate_affiliate_code', 'get_affiliate')
            ORDER BY 1
            SQL),
    );

    // Parameter NAMES are pinned too, since PostgreSQL reports them here: renaming
    // `p_expected_code_id` would be a silent change to the compare-and-swap contract.
    expect($signatures)->toBe([
        'get_affiliate(p_affiliate_id bigint)',
        'rotate_affiliate_code(p_affiliate_id bigint, p_expected_code_id bigint, p_actor_user_id bigint)',
    ]);
});

// ── Versioned policies ──────────────────────────────────────────────────────────

it('ships no policy row, so nothing believes the programme is running', function () {
    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM affiliate_program_policies')->c)->toBe(0);
});

it('bounds every policy value and refuses an absurd one', function () {
    $insert = function (array $overrides = []) {
        $row = array_merge([
            'model' => 'code_then_last_click', 'window' => 30, 'bps' => 1500,
            'delay' => 14, 'threshold' => 10000, 'currency' => 'XOF', 'version' => 1,
        ], $overrides);

        Fx::owner()->insert(
            "INSERT INTO affiliate_program_policies (public_id, version, status, attribution_model, attribution_window_days, default_commission_bps, payable_delay_days, payout_threshold_minor, payout_currency, manual_payout_only, effective_from, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, 'draft', ?, ?, ?, ?, ?, ?, true, now(), now(), now())",
            [$row['version'], $row['model'], $row['window'], $row['bps'], $row['delay'], $row['threshold'], $row['currency']],
        );
    };

    // The arbitrated policy is accepted.
    $insert();

    // Everything out of bounds is refused.
    expect(fn () => $insert(['version' => 2, 'window' => 0]))->toThrow(QueryException::class);
    expect(fn () => $insert(['version' => 3, 'window' => 366]))->toThrow(QueryException::class);
    expect(fn () => $insert(['version' => 4, 'bps' => -1]))->toThrow(QueryException::class);
    // 100 % commission is absurd and refused.
    expect(fn () => $insert(['version' => 5, 'bps' => 10000]))->toThrow(QueryException::class);
    expect(fn () => $insert(['version' => 6, 'delay' => 366]))->toThrow(QueryException::class);
    expect(fn () => $insert(['version' => 7, 'threshold' => -1]))->toThrow(QueryException::class);
    expect(fn () => $insert(['version' => 8, 'currency' => 'xof']))->toThrow(QueryException::class);
    // An unreviewed attribution model cannot be smuggled in.
    expect(fn () => $insert(['version' => 9, 'model' => 'first_click']))->toThrow(QueryException::class);
    // Version is unique.
    expect(fn () => $insert(['version' => 1]))->toThrow(QueryException::class);
});

it('refuses an inverted effective period and more than one active policy', function () {
    expect(fn () => Fx::owner()->insert(
        "INSERT INTO affiliate_program_policies (public_id, version, status, attribution_model, attribution_window_days, default_commission_bps, payable_delay_days, payout_threshold_minor, payout_currency, manual_payout_only, effective_from, effective_until, created_at, updated_at)
         VALUES (gen_random_uuid(), 1, 'draft', 'code_then_last_click', 30, 1500, 14, 10000, 'XOF', true, now(), now() - interval '1 day', now(), now())"
    ))->toThrow(QueryException::class);

    $active = function (int $version) {
        Fx::owner()->insert(
            "INSERT INTO affiliate_program_policies (public_id, version, status, attribution_model, attribution_window_days, default_commission_bps, payable_delay_days, payout_threshold_minor, payout_currency, manual_payout_only, effective_from, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, 'active', 'code_then_last_click', 30, 1500, 14, 10000, 'XOF', true, now(), now(), now())",
            [$version],
        );
    };

    $active(10);

    // Two overlapping active versions would make "which rate applied" unanswerable.
    expect(fn () => $active(11))->toThrow(QueryException::class);
});
