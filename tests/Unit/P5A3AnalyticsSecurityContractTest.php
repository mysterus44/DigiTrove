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

it('keeps the ANALYTICS Filament surface read-only and free of operations or exports', function () use ($root) {
    // Enumerated EXPLICITLY, like its P5-A3C sibling. The previous version globbed
    // `app/Filament/Pages/*.php` and so annexed every page of every other domain: the
    // P6-B1 CRM export page would have failed an ANALYTICS contract for a reason that
    // has nothing to do with analytics. The guard is AIMED, not weakened — there is no
    // "ignore CRM" exclusion, and a new analytics page/widget must be listed to be
    // covered.
    $files = array_merge(
        [$root.'/app/Filament/Pages/AnalyticsDashboard.php'],
        glob($root.'/app/Filament/Widgets/Analytics*.php') ?: [],
        glob($root.'/resources/views/filament/widgets/analytics-*.blade.php') ?: [],
    );

    // Fail closed: a renamed or deleted analytics file must break this test rather than
    // silently shrink the scanned surface and report a vacuous pass.
    expect($files)->toHaveCount(11);

    foreach ($files as $file) {
        expect(is_file($file))->toBeTrue("analytics surface file is missing: {$file}");
    }

    $surface = implode("\n", array_map('file_get_contents', $files));

    expect($surface)->not->toContain('Artisan::call')
        ->not->toContain('Process::run')
        ->not->toContain('analytics:rollup')
        ->not->toContain('partitions:ensure')
        ->not->toContain('export')
        ->not->toContain('csv')
        ->not->toContain('digitrove_analytics_worker')
        ->not->toContain('SQLSTATE');

    // The CRM pages exist and are deliberately OUTSIDE this analytics surface.
    $crmPages = glob($root.'/app/Filament/Pages/Crm*.php') ?: [];
    expect($crmPages)->not->toBe([]);

    foreach ($crmPages as $crmPage) {
        expect($files)->not->toContain($crmPage);
    }
});
