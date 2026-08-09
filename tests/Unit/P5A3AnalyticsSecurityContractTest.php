<?php

$root = dirname(__DIR__, 2);

it('keeps dashboard credentials dedicated and fail closed', function () use ($root) {
    $database = file_get_contents($root.'/config/database.php');
    $analytics = file_get_contents($root.'/config/analytics.php');
    $support = file_get_contents($root.'/app/Support/AnalyticsDashboardConfig.php');

    expect($database)->toContain("'pgsql_analytics_reader'")
        ->toContain("env('ANALYTICS_READER_DB_USERNAME')")
        ->toContain("env('ANALYTICS_READER_DB_PASSWORD')")
        ->not->toContain("env('ANALYTICS_READER_DB_USERNAME',")
        ->not->toContain("env('ANALYTICS_READER_DB_PASSWORD',")
        ->and($analytics)->toContain("env('ANALYTICS_DASHBOARD_ENABLED', false)")
        ->and($support)->not->toContain('env(');
});

it('provisions all three login passwords in one CI psql session', function () use ($root) {
    $workflow = file_get_contents($root.'/.github/workflows/ci.yml');
    $provisioning = file_get_contents($root.'/docker/postgres/provision-runtime-roles.sql');
    $command = file_get_contents($root.'/app/Console/Commands/ProvisionRuntimeRoles.php');

    preg_match(
        '/^      - name: Provision the runtime privilege boundary roles\R.*?(?=^      - name:|\z)/ms',
        $workflow,
        $stepMatch,
    );

    expect($stepMatch)->toHaveCount(1);
    $step = $stepMatch[0];

    expect(substr_count($step, 'psql -h'))->toBe(1)
        ->and($step)->toContain('ANALYTICS_READER_PASSWORD: digitrove_analytics_reader_local')
        ->toContain("SET digitrove.analytics_reader_password TO '\$ANALYTICS_READER_PASSWORD'")
        ->and($provisioning)->toContain("current_setting('digitrove.analytics_reader_password', true)")
        ->toContain('digitrove.analytics_reader_password must be set before running this script')
        ->and($command)->toContain("set_config('digitrove.analytics_reader_password', ?, false)")
        ->toContain("set_config('digitrove.analytics_reader_password', '', false)");
});

it('keeps dashboard queries projection-only read-only and identity-free', function () use ($root) {
    $reader = file_get_contents($root.'/app/Services/Analytics/Read/AnalyticsReader.php');
    $overview = file_get_contents($root.'/app/Services/Analytics/Read/AnalyticsOverviewQuery.php');
    $sales = file_get_contents($root.'/app/Services/Analytics/Read/AnalyticsSalesQuery.php');
    $queries = $overview.$sales;

    expect($reader)->toContain('AnalyticsDashboardConfig::CONNECTION')
        ->toContain('transactionLevel() !== 0')
        ->toContain('SET TRANSACTION READ ONLY')
        ->toContain('SELECT session_user, current_user')
        ->toContain('SHOW transaction_read_only')
        ->not->toContain("DB::connection('pgsql')")
        ->and($queries)->toContain('public.daily_funnel_stats')
        ->toContain('public.daily_sales_stats')
        ->not->toContain(' public.orders')
        ->not->toContain(' public.order_items')
        ->not->toContain(' public.events')
        ->not->toContain('customer_email')
        ->not->toContain('order_number')
        ->toContain('analytics:v1')
        ->toContain('role=admin')
        ->toContain('scope=global')
        ->toContain('timezone=UTC')
        ->not->toContain('user_id=')
        ->not->toContain('visitor_id=');
});

/**
 * Tokens that must never appear on the ANALYTICS admin surface. P5-A3D deferred every
 * operation to CLI/scheduler, so an analytics screen able to trigger a rollup, name the
 * worker role, leak a SQLSTATE or offer an export would silently re-open a closed
 * boundary.
 *
 * @return list<string>
 */
function p5a3ForbiddenSurfaceTokens(): array
{
    return [
        'Artisan::call',
        'Process::run',
        'analytics:rollup',
        'partitions:ensure',
        'export',
        'csv',
        'digitrove_analytics_worker',
        'SQLSTATE',
    ];
}

/**
 * @return list<string> the forbidden tokens actually present in $content
 */
function p5a3SurfaceViolations(string $content): array
{
    return array_values(array_filter(
        p5a3ForbiddenSurfaceTokens(),
        static fn (string $token): bool => str_contains($content, $token),
    ));
}

/**
 * THE canonical enumeration of the analytics admin surface.
 *
 * Deliberately EXPLICIT. The previous version globbed `app/Filament/Pages/*.php`, which
 * annexed every page of every other domain: P6-B0's CRM Segments page states in its own
 * docblock that it offers "no export action of any kind" and comments that a refusal
 * carries "No SQLSTATE, no driver text" — and a raw text glob cannot tell those promises
 * apart from violations of them. The guard is AIMED, not weakened: there is no
 * "ignore CRM" exclusion, and a new analytics page/widget must be listed to be covered.
 *
 * @return list<string>
 */
function p5a3AnalyticsSurfaceFiles(string $root): array
{
    return array_merge(
        [$root.'/app/Filament/Pages/AnalyticsDashboard.php'],
        glob($root.'/app/Filament/Widgets/Analytics*.php') ?: [],
        glob($root.'/resources/views/filament/widgets/analytics-*.blade.php') ?: [],
    );
}

it('keeps the ANALYTICS Filament surface read-only and free of operations or exports', function () use ($root) {
    $files = p5a3AnalyticsSurfaceFiles($root);

    // Fail closed: a renamed or deleted analytics file must BREAK this test rather than
    // silently shrink the scanned surface and report a vacuous pass.
    expect($files)->toHaveCount(11);

    foreach ($files as $file) {
        expect(is_file($file))->toBeTrue("analytics surface file is missing: {$file}");
    }

    expect(p5a3SurfaceViolations(implode("\n", array_map('file_get_contents', $files))))->toBe([]);
});

it('still rejects an analytics surface that gained an export or operation affordance', function () {
    // The guard keeps its teeth, proven against synthetic content so the proof does not
    // depend on the repository ever actually being wrong.
    expect(p5a3SurfaceViolations('<x-filament::button wire:click="export">Download</x-filament::button>'))
        ->toBe(['export'])
        ->and(p5a3SurfaceViolations('Storage::disk("local")->put($csvPath, $rows);'))->toBe(['csv'])
        ->and(p5a3SurfaceViolations('Artisan::call("analytics:rollup");'))
        ->toBe(['Artisan::call', 'analytics:rollup'])
        ->and(p5a3SurfaceViolations('connect as digitrove_analytics_worker'))
        ->toBe(['digitrove_analytics_worker'])
        ->and(p5a3SurfaceViolations('the raw SQLSTATE was rendered'))->toBe(['SQLSTATE'])
        ->and(p5a3SurfaceViolations('a clean analytics widget'))->toBe([]);
});

it('scopes the analytics contract to analytics files only, never to CRM pages', function () use ($root) {
    $files = p5a3AnalyticsSurfaceFiles($root);
    $crmPages = glob($root.'/app/Filament/Pages/Crm*.php') ?: [];

    // CRM pages exist (P6-B0 added Contacts and Segments)…
    expect($crmPages)->not->toBe([]);

    // …and none of them belongs to the analytics surface. They are governed by their own
    // contract, P6B0SecurityContractTest, which scans CODE with comments stripped.
    foreach ($crmPages as $crmPage) {
        expect($files)->not->toContain($crmPage);
    }
});
