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

// P7 (D-070) added `000035`, which is BLOG schema and owns nothing affiliate. The three
// teeth of this contract are unchanged in nature: the total, the affiliate migrations each
// present exactly once, and the next number still absent.
it('sits behind the P6-D4 boundary: 51 migrations, 000029 to 000034 present, no 000036', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(51)
        ->and(glob($root.'/database/migrations/2026_07_14_000029*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000030*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000031*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000032*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000033*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000034*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000036*.php') ?: [])->toBe([]);
});

/**
 * The decisive contract of this gate. P6-D0 is a schema, full stop: no candidature flow,
 * no click capture, no commission engine, no payout run, no admin screen.
 *
 * Scanning the WHOLE application tree rather than a hand-written allowlist means a future
 * `AffiliateService` cannot be added quietly — it has to break this test first.
 */
// À VALIDER (P6-D1, D-058): P6-D1 legitimately adds a governance surface, so this contract is
// rescoped from an ABSENCE to an EXACT inventory. Any file mentioning "affiliate" that is not
// on this list still fails — a future AffiliateService, a candidature flow, a payout screen,
// anything from a later gate arriving early — so the teeth are kept, only the scope moved.
it('ships exactly the P6-D1 governance surface and no other affiliate file', function () {
    $files = p6d0ApplicationFiles();

    // Fail closed: a vacuous pass over an empty file list would prove nothing.
    expect(count($files))->toBeGreaterThan(200);

    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $offenders = [];

    foreach ($files as $file) {
        $code = str_ends_with($file, '.blade.php')
            ? Scanner::bladeMarkup($file)
            : Scanner::phpCode($file);

        if (stripos($code, 'affiliate') !== false) {
            $offenders[] = ltrim(str_replace('\\', '/', substr(str_replace('\\', '/', $file), strlen($root))), '/');
        }
    }

    sort($offenders);

    // P6-D1.1 (D-059) adds the lifecycle surface. Named one by one, as before: the list is
    // the contract, and anything not on it still fails.
    expect($offenders)->toBe([
        'app/Console/Commands/PromoteAffiliateCommissions.php',
        'app/Filament/Pages/AffiliateLifecycle.php',
        'app/Filament/Pages/AffiliatePayouts.php',
        'app/Filament/Pages/AffiliateProgramme.php',
        'app/Filament/Pages/Concerns/AuthorizesAffiliateAdmin.php',
        'app/Http/Controllers/Storefront/AffiliateTouchController.php',
        'app/Jobs/ProcessAffiliateAttribution.php',
        'app/Jobs/ProcessAffiliateCommissionAccrual.php',
        'app/Jobs/ProcessAffiliateRefundReversal.php',
        'app/Listeners/QueueAffiliateRefundReversal.php',
        'app/Listeners/ResolveAffiliateAttribution.php',
        'app/Policies/AffiliatePolicyGovernancePolicy.php',
        'app/Providers/AppServiceProvider.php',
        'app/Services/Affiliate/AffiliateAttributionService.php',
        'app/Services/Affiliate/AffiliateCommissionService.php',
        'app/Services/Affiliate/AffiliateDetail.php',
        'app/Services/Affiliate/AffiliateLifecycleService.php',
        'app/Services/Affiliate/AffiliateOperationException.php',
        'app/Services/Affiliate/AffiliatePayoutService.php',
        'app/Services/Affiliate/AffiliatePolicy.php',
        'app/Services/Affiliate/AffiliatePolicyService.php',
        'app/Services/Affiliate/AffiliateRefundReversalService.php',
        'app/Services/Affiliate/AffiliateRefusalReason.php',
        'app/Services/Affiliate/AffiliateReviewDecision.php',
        'app/Services/Affiliate/AffiliateSummary.php',
        'app/Services/Affiliate/AffiliateTouchCaptureService.php',
        'app/Services/Affiliate/AffiliateTransition.php',
        'app/Services/Affiliate/Concerns/UsesAffiliateAuthority.php',
        'app/Support/AffiliateConfig.php',
        'config/affiliate.php',
        'resources/views/filament/pages/affiliate-lifecycle.blade.php',
        'resources/views/filament/pages/affiliate-payouts.blade.php',
        'routes/console.php',
        'routes/web.php',
    ]);
});

// À VALIDER (P6-D1, D-058): rescoped to exact per-layer inventories. The fragile `**` globs
// (PHP glob() does not recurse on `**`) are replaced with a deterministic recursive scan, so
// a nested affiliate file cannot slip past. Governance adds NO route, command, job, listener,
// mail or model — those layers must stay empty — and only the exact governance files elsewhere.
it('adds no affiliate route, command, job, listener, mail or model, and only the governance surface elsewhere', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));

    // Every routing file except the two named below stays completely free of the word.
    foreach (glob($root.'/routes/*.php') ?: [] as $file) {
        if (! in_array(basename($file), ['web.php', 'console.php'], true)) {
            expect(stripos(Scanner::phpCode($file), 'affiliate'))->toBeFalse();
        }
    }

    // `console.php` carries the P6-D3 payable sweep. Exempting the FILE would be a hole —
    // any affiliate schedule could then be added unnoticed — so its affiliate-bearing TOKENS
    // are inventoried exactly, like `web.php` below: the config class that gates the sweep,
    // and the bare word behind the command name. A third token fails here.
    preg_match_all('/\w*affiliate\w*/i', Scanner::phpCode($root.'/routes/console.php'), $consoleTokens);
    $distinctConsole = array_values(array_unique($consoleTokens[0]));
    sort($distinctConsole);

    expect($distinctConsole)->toBe(['AffiliateConfig', 'affiliate']);

    // `web.php` carries the P6-D2 capture routes, so it cannot be held to zero. Exempting
    // the whole FILE would be a hole: any affiliate route could then be added unnoticed.
    // Instead the affiliate-bearing TOKENS it may contain are inventoried exactly — the
    // controller class and the bare word behind the route names, the throttle alias and the
    // URI. A third token, whatever it is called, fails here.
    preg_match_all('/\w*affiliate\w*/i', Scanner::phpCode($root.'/routes/web.php'), $tokens);
    $distinct = array_values(array_unique($tokens[0]));
    sort($distinct);

    expect($distinct)->toBe(['AffiliateTouchController', 'affiliate']);

    /** @return list<string> Affiliate-named files under $dir, relative to root, sorted. */
    $affiliateFilesUnder = static function (string $dir) use ($root): array {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && stripos($file->getFilename(), 'ffiliate') !== false) {
                $out[] = ltrim(str_replace('\\', '/', substr(str_replace('\\', '/', $file->getPathname()), strlen($root))), '/');
            }
        }

        sort($out);

        return $out;
    };

    // Layers a live programme would need and that this gate still must not touch. NOTE the
    // absence of `app/Listeners`: P6-D2 puts exactly one file there, inventoried below. It
    // left this list by being NAMED somewhere else, never by the assertion being dropped.
    foreach (['app/Mail', 'app/Models'] as $layer) {
        expect($affiliateFilesUnder($root.'/'.$layer))->toBe([], "unexpected affiliate file under {$layer}");
    }

    // P6-D2: one listener and one job, each carrying `order_id` alone. They left the
    // "must stay empty" list above by being NAMED here, never by an assertion being
    // dropped. There is still no affiliate command, mail or model.
    expect($affiliateFilesUnder($root.'/app/Listeners'))->toBe([
        'app/Listeners/QueueAffiliateRefundReversal.php',
        'app/Listeners/ResolveAffiliateAttribution.php',
    ]);
    // P6-D3 adds exactly one operator command: the payable sweep. It left the "must stay
    // empty" list above by being NAMED here, never by an assertion being dropped. There is
    // still no affiliate mail and no affiliate model.
    expect($affiliateFilesUnder($root.'/app/Console/Commands'))->toBe([
        'app/Console/Commands/PromoteAffiliateCommissions.php',
    ]);
    expect($affiliateFilesUnder($root.'/app/Jobs'))->toBe([
        'app/Jobs/ProcessAffiliateAttribution.php',
        'app/Jobs/ProcessAffiliateCommissionAccrual.php',
        'app/Jobs/ProcessAffiliateRefundReversal.php',
    ]);

    // The exact governance surface, by inventory.
    expect($affiliateFilesUnder($root.'/app/Policies'))->toBe([
        'app/Policies/AffiliatePolicyGovernancePolicy.php',
    ]);
    expect($affiliateFilesUnder($root.'/app/Filament'))->toBe([
        'app/Filament/Pages/AffiliateLifecycle.php',
        'app/Filament/Pages/AffiliatePayouts.php',
        'app/Filament/Pages/AffiliateProgramme.php',
        'app/Filament/Pages/Concerns/AuthorizesAffiliateAdmin.php',
    ]);
    expect($affiliateFilesUnder($root.'/app/Http/Controllers'))->toBe([
        'app/Http/Controllers/Storefront/AffiliateTouchController.php',
    ]);
    expect($affiliateFilesUnder($root.'/app/Services'))->toBe([
        'app/Services/Affiliate/AffiliateAttributionService.php',
        'app/Services/Affiliate/AffiliateCommissionService.php',
        'app/Services/Affiliate/AffiliateDetail.php',
        'app/Services/Affiliate/AffiliateLifecycleService.php',
        'app/Services/Affiliate/AffiliateOperationException.php',
        'app/Services/Affiliate/AffiliatePayoutService.php',
        'app/Services/Affiliate/AffiliatePolicy.php',
        'app/Services/Affiliate/AffiliatePolicyService.php',
        'app/Services/Affiliate/AffiliateRefundReversalService.php',
        'app/Services/Affiliate/AffiliateRefusalReason.php',
        'app/Services/Affiliate/AffiliateReviewDecision.php',
        'app/Services/Affiliate/AffiliateSummary.php',
        'app/Services/Affiliate/AffiliateTouchCaptureService.php',
        'app/Services/Affiliate/AffiliateTransition.php',
        'app/Services/Affiliate/Concerns/UsesAffiliateAuthority.php',
    ]);
    expect($affiliateFilesUnder($root.'/config'))->toBe([
        'config/affiliate.php',
    ]);
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
