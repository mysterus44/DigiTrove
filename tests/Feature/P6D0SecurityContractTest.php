<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;
use Tests\Support\SourceScanner as Scanner;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-D0 security contract — fail-closed.
 *
 * This gate ships a DORMANT database foundation and nothing else. Every assertion below
 * states an ABSENCE, because the risk here is not a wrong calculation: it is a premature
 * one. An affiliate programme that looks live before its authorities exist would pay real
 * money on rules nobody reviewed.
 *
 * The migration is scanned as CODE (comments stripped by the tokenizer) so it may keep
 * documenting exactly what it refuses to do without tripping its own alarm.
 */
function p6d0MigrationPath(): string
{
    $matches = glob(dirname(__DIR__, 2).'/database/migrations/2026_07_14_000029*.php') ?: [];

    expect($matches)->toHaveCount(1);

    return $matches[0];
}

/** Every PHP/Blade file under the application, routes, config and view layers. */
function p6d0ApplicationFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['app', 'routes', 'config', 'resources'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

// ── The gate boundary ────────────────────────────────────────────────────────────

it('adds exactly one migration and never opens the next one', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(45)
        ->and(glob($root.'/database/migrations/2026_07_14_000029*.php'))->toHaveCount(1)
        // P6-D1 has not started: no authority, no bootstrap, no second schema move.
        ->and(glob($root.'/database/migrations/2026_07_14_000030*.php') ?: [])->toBe([]);
});

/**
 * The decisive contract of this gate. P6-D0 is a schema, full stop: no candidature flow,
 * no click capture, no commission engine, no payout run, no admin screen.
 *
 * Scanning the WHOLE application tree rather than a hand-written allowlist means a future
 * `AffiliateService` cannot be added quietly — it has to break this test first.
 */
it('ships no application surface at all: not one file mentions an affiliate', function () {
    $files = p6d0ApplicationFiles();

    // Fail closed: a vacuous pass over an empty file list would prove nothing.
    expect(count($files))->toBeGreaterThan(200);

    $offenders = [];

    foreach ($files as $file) {
        $code = str_ends_with($file, '.blade.php')
            ? Scanner::bladeMarkup($file)
            : Scanner::phpCode($file);

        if (stripos($code, 'affiliate') !== false) {
            $offenders[] = $file;
        }
    }

    expect($offenders)->toBe([]);
});

it('adds no route, no scheduled task and no queued job', function () {
    $root = dirname(__DIR__, 2);

    // Nothing on the public or admin routing surface.
    foreach (glob($root.'/routes/*.php') ?: [] as $file) {
        expect(stripos(Scanner::phpCode($file), 'affiliate'))->toBeFalse();
    }

    // No class file was created under the layers a live programme would need.
    foreach (['Console/Commands', 'Jobs', 'Listeners', 'Mail', 'Policies', 'Services', 'Filament'] as $layer) {
        $matches = glob($root.'/app/'.$layer.'/**/*ffiliate*.php') ?: [];

        expect(array_merge($matches, glob($root.'/app/'.$layer.'/*ffiliate*.php') ?: []))->toBe([]);
    }

    expect(glob($root.'/app/Models/*ffiliate*.php') ?: [])->toBe([])
        ->and(glob($root.'/config/*ffiliate*.php') ?: [])->toBe([]);
});

it('never turns affiliation into a user role', function () {
    // D-014 rejected `users.role = affiliate` before P1: a role cannot carry an
    // application, a status history, a balance or an audit trail.
    expect(array_map(
        static fn (UnitEnum $case): string => $case->name,
        UserRole::cases(),
    ))->toBe(['Customer', 'Admin', 'Staff']);
});

// ── Monetary safety ──────────────────────────────────────────────────────────────

it('declares no approximate numeric type anywhere on the money path', function () {
    $code = Scanner::phpCode(p6d0MigrationPath());

    // Laravel column builders that would produce REAL/DOUBLE/NUMERIC.
    foreach (['->float(', '->double(', '->decimal(', '->unsignedDecimal(', 'NUMERIC', 'REAL', 'DOUBLE PRECISION', 'MONEY'] as $token) {
        expect(str_contains($code, $token))->toBeFalse("forbidden numeric type in the migration: {$token}");
    }

    // And no rounding helper: an integer schema must never need one.
    foreach (['round(', 'floor(', 'ceil(', 'intdiv('] as $token) {
        expect(str_contains($code, $token))->toBeFalse("the schema must not compute: {$token}");
    }
});

it('keeps the commission rate on the versioned policy, never on the affiliate', function () {
    // A mutable rate stored on `affiliates` would destroy temporality: a past commission
    // could no longer be explained by the rate that actually applied to it.
    $rateColumns = Fx::owner()->select(<<<'SQL'
        SELECT table_name, column_name FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name LIKE 'affiliate%'
          AND (column_name LIKE '%_bps%' OR column_name LIKE '%rate%')
        ORDER BY 1, 2
        SQL);

    $byTable = [];

    foreach ($rateColumns as $column) {
        $byTable[$column->table_name][] = $column->column_name;
    }

    expect($byTable)->toHaveKey('affiliate_program_policies')
        ->and($byTable)->toHaveKey('affiliate_commissions')
        // The rate lives on the policy and is SNAPSHOT on the commission — nowhere else.
        ->and(array_keys($byTable))->toBe(['affiliate_commissions', 'affiliate_program_policies'])
        ->and($byTable['affiliate_commissions'])->toBe(['rate_bps_snapshot']);
});

it('invents no currency conversion', function () {
    // P6-A1.1 forbids any multi-currency total. A payout reaching its threshold through
    // an invented exchange rate would be that same violation wearing a different hat.
    $columns = Fx::owner()->select(<<<'SQL'
        SELECT table_name, column_name FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name LIKE 'affiliate%'
        SQL);

    foreach ($columns as $column) {
        foreach (['exchange', 'fx_', 'converted', 'base_currency', 'rate_to_'] as $token) {
            expect(str_contains($column->column_name, $token))
                ->toBeFalse("{$column->table_name}.{$column->column_name} implies a currency conversion");
        }
    }

    expect(str_contains(Scanner::phpCode(p6d0MigrationPath()), 'exchange_rate'))->toBeFalse();
});

// ── Authority boundary ───────────────────────────────────────────────────────────

it('creates no operational authority, no role and no runtime grant', function () {
    $code = Scanner::phpCode(p6d0MigrationPath());

    foreach (['SECURITY DEFINER', 'CREATE ROLE', 'CREATE USER', 'GRANT '] as $token) {
        expect(str_contains($code, $token))->toBeFalse("P6-D0 must not emit: {$token}");
    }

    // The three trigger functions are integrity guards, deliberately NOT SECURITY
    // DEFINER: they grant nothing, they only refuse. A SECURITY DEFINER here would be an
    // authority, and no affiliate authority is allowed to exist yet.
    $functions = [
        'enforce_affiliate_ledger_append_only',
        'enforce_affiliate_policy_immutability',
        'enforce_affiliate_touch_subject',
    ];

    foreach ($functions as $name) {
        expect((bool) Fx::owner()->selectOne(
            'SELECT p.prosecdef FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = ? AND p.proname = ?', ['public', $name],
        )->prosecdef)->toBeFalse("{$name} must not be SECURITY DEFINER");

        // PUBLIC holds no EXECUTE: `000012` revokes it by default for the migrator role.
        expect((bool) Fx::owner()->selectOne(
            'SELECT has_function_privilege(?, ?, ?) AS granted',
            ['public', 'public.'.$name.'()', 'EXECUTE'],
        )->granted)->toBeFalse("PUBLIC must not execute {$name}");
    }
});

/**
 * The distinction this gate turns on: `visitors` is an IDENTITY row (migration `000004`,
 * P1 era, UUID primary key) that also happens to carry marketing columns. Referencing it
 * as the SUBJECT of a touch is legitimate — a pre-login click has to be anchored to
 * someone. Reading its `first_touch_*` columns as PROOF of who earned a commission is
 * what D-057 forbids, and what `affiliate_touches` exists to replace.
 */
it('never treats a marketing signal as a financial authority', function () {
    $code = Scanner::phpCode(p6d0MigrationPath());

    // D-037 froze analytics events as NON authoritative, without a transactional FK.
    // None of these may ever justify paying money.
    foreach (['analytics_sessions', 'analytics_daily', 'first_touch', 'utm_', 'daily_funnel'] as $token) {
        expect(str_contains($code, $token))->toBeFalse("the affiliate schema must not depend on: {$token}");
    }

    // And it grows no marketing column of its own.
    $columns = Fx::owner()->select(<<<'SQL'
        SELECT table_name, column_name FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name LIKE 'affiliate%'
        SQL);

    foreach ($columns as $column) {
        foreach (['utm', 'campaign', 'medium', 'referrer', 'landing'] as $token) {
            expect(str_contains($column->column_name, $token))
                ->toBeFalse("{$column->table_name}.{$column->column_name} is a marketing signal, not a financial fact");
        }
    }

    $foreignTargets = array_map(
        static fn (object $r): string => (string) $r->table_name,
        Fx::owner()->select(<<<'SQL'
            SELECT DISTINCT ccu.table_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name LIKE 'affiliate%'
              AND ccu.table_name NOT LIKE 'affiliate%'
            ORDER BY 1
            SQL),
    );

    // Exactly the authoritative commerce and identity tables — not one analytics table.
    expect($foreignTargets)->toBe(['order_items', 'orders', 'refunds', 'users', 'visitors']);
});

/**
 * The affiliate schema must never become a veto on a retention or anonymisation policy.
 * Anchoring is enforced at INSERT by a trigger, NOT by a CHECK — a CHECK would be
 * re-evaluated by the `ON DELETE SET NULL` update and would block every visitor erasure
 * for ever. The full lifecycle proof lives in `P6D0AffiliateLifecycleTest`.
 */
it('never vetoes a visitor erasure', function () {
    $trigger = Fx::owner()->selectOne(<<<'SQL'
        SELECT pg_get_triggerdef(t.oid) AS definition
        FROM pg_trigger AS t
        WHERE NOT t.tgisinternal AND t.tgname = 'affiliate_touches_subject_trigger'
        SQL);

    expect($trigger)->not->toBeNull()
        ->and($trigger->definition)->toContain('BEFORE INSERT')
        ->and($trigger->definition)->not->toContain('UPDATE')
        ->and($trigger->definition)->not->toContain('DELETE');

    // And no CHECK constraint reintroduces the veto by the back door.
    $checks = Fx::owner()->select(<<<'SQL'
        SELECT conname FROM pg_constraint
        WHERE contype = 'c' AND conrelid = 'public.affiliate_touches'::regclass
          AND pg_get_constraintdef(oid) LIKE '%visitor_id%'
        SQL);

    expect($checks)->toBe([]);
});

it('binds no payment provider and stores no payment credential', function () {
    $code = Scanner::phpCode(p6d0MigrationPath());

    // No endpoint, no provider name, no credential: a payout is manual by decision,
    // and a Mobile Money identifier needs its own reviewed gate.
    foreach (['wave', 'orange_money', 'mobile_money', 'cinetpay', 'powerpay', 'stripe', 'paypal', 'https://', 'iban', 'msisdn'] as $token) {
        expect(stripos($code, $token))->toBeFalse("P6-D0 must not name a provider or credential: {$token}");
    }
});
