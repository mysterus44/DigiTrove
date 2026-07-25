<?php

$root = dirname(__DIR__, 2);

it('keeps analytics operations fail closed and free of credential fallbacks', function () use ($root) {
    $database = file_get_contents($root.'/config/database.php');
    $analytics = file_get_contents($root.'/config/analytics.php');
    $support = file_get_contents($root.'/app/Support/AnalyticsOperationsConfig.php');

    expect($database)->toContain("'pgsql_analytics_worker'")
        ->toContain("env('ANALYTICS_WORKER_DB_USERNAME')")
        ->toContain("env('ANALYTICS_WORKER_DB_PASSWORD')")
        ->not->toContain("env('ANALYTICS_WORKER_DB_USERNAME',")
        ->not->toContain("env('ANALYTICS_WORKER_DB_PASSWORD',")
        ->and($analytics)->toContain("env('ANALYTICS_OPERATIONS_ENABLED', false)")
        ->toContain("env('ANALYTICS_ROLLUPS_ENABLED', false)")
        ->toContain("env('ANALYTICS_PARTITIONS_ENABLED', false)")
        ->and($support)->not->toContain('env(');
});

it('uses only the dedicated connection and prepared P5-A2 authorities', function () use ($root) {
    $rollup = file_get_contents($root.'/app/Services/Analytics/AuthoritativeRollupService.php');
    $partitions = file_get_contents($root.'/app/Services/Analytics/EventPartitionService.php');

    foreach ([$rollup, $partitions] as $service) {
        expect($service)->toContain("DB::connection('pgsql_analytics_worker')")
            ->toContain('transactionLevel() !== 0')
            ->toContain('digitrove_analytics_worker')
            ->not->toContain('env(')
            ->not->toContain('Http::')
            ->not->toContain('DB::unprepared');
    }

    expect($rollup)->toContain('refresh_authoritative_daily_analytics')
        ->and($partitions)->toContain('ensure_analytics_events_month_partition')
        ->toContain('audit_analytics_event_partitions');
});

it('registers bounded commands and conditional non-overlapping schedules', function () use ($root) {
    $rollupCommand = file_get_contents($root.'/app/Console/Commands/RefreshAnalyticsRollups.php');
    $partitionCommand = file_get_contents($root.'/app/Console/Commands/EnsureAnalyticsPartitions.php');
    $auditCommand = file_get_contents($root.'/app/Console/Commands/AuditAnalyticsPartitions.php');
    $schedules = file_get_contents($root.'/routes/console.php');
    $guard = file_get_contents($root.'/tests/Feature/P4BDownloadLogsTest.php');

    expect($rollupCommand)->toContain('analytics:rollup')
        ->toContain('maxBackfillDays')
        ->and($partitionCommand)->toContain('analytics:partitions:ensure')
        ->toContain('partitionMonthsAhead')
        ->toContain('partitionMonthsBehind')
        ->and($auditCommand)->toContain('analytics:partitions:audit')
        ->and($schedules)->toContain('AnalyticsOperationsConfig::enabled()')
        ->toContain('withoutOverlapping()')
        ->toContain('onOneServer()')
        ->and($guard)->toContain("'Analytics/AuthoritativeRollupService.php'")
        ->toContain("'Analytics/EventPartitionService.php'");
});
