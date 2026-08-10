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

it('creates exactly migration 000029 and no 000030', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(45)
        ->and(glob($root.'/database/migrations/2026_07_14_000029*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000030*.php') ?: [])->toBe([]);
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

it('creates no new database role and no premature authority function', function () {
    expect(Fx::owner()->select("SELECT rolname FROM pg_roles WHERE rolname LIKE '%affiliate%'"))->toBe([]);

    // Exactly three functions, and all three are structural INTEGRITY guards behind a
    // trigger — none of them is an operational authority. Those belong to P6-D1/D2/D3, and
    // freezing their contracts here would decide too early.
    $functions = array_map(
        static fn (object $r): string => (string) $r->proname,
        Fx::owner()->select(<<<'SQL'
            SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate%' ORDER BY 1
            SQL),
    );

    expect($functions)->toBe([
        'enforce_affiliate_ledger_append_only',
        'enforce_affiliate_policy_immutability',
        'enforce_affiliate_touch_subject',
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
