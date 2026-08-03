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
