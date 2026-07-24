<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

it('rolls back the three P5-A0 boundaries independently without touching P1 through P4', function () {
    $events = '2026_07_14_000014_create_partitioned_events_table.php';
    $sessions = '2026_07_14_000015_create_analytics_sessions_table.php';
    $rollups = '2026_07_14_000016_create_analytics_rollups_tables.php';
    $harness = new PhaseMigrationHarness('digitrove_p5a0_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($rollups);

        expect($applied)->toHaveCount(32)
            ->and(end($applied))->toBe('2026_07_14_000016_create_analytics_rollups_tables')
            ->and($harness->hasTable('events'))->toBeTrue()
            ->and($harness->hasTable('events_default'))->toBeTrue()
            ->and($harness->hasTable('analytics_sessions'))->toBeTrue()
            ->and($harness->hasTable('daily_sales_stats'))->toBeTrue()
            ->and($harness->hasTable('daily_product_stats'))->toBeTrue()
            ->and($harness->hasTable('daily_funnel_stats'))->toBeTrue();

        expect($harness->rollbackExactMigrations([$rollups]))->toBe([
            '2026_07_14_000016_create_analytics_rollups_tables',
        ])
            ->and($harness->hasTable('daily_sales_stats'))->toBeFalse()
            ->and($harness->hasTable('daily_product_stats'))->toBeFalse()
            ->and($harness->hasTable('daily_funnel_stats'))->toBeFalse()
            ->and($harness->hasTable('analytics_sessions'))->toBeTrue()
            ->and($harness->hasTable('events'))->toBeTrue();

        expect($harness->rollbackExactMigrations([$sessions]))->toBe([
            '2026_07_14_000015_create_analytics_sessions_table',
        ])
            ->and($harness->hasTable('analytics_sessions'))->toBeFalse()
            ->and($harness->hasTable('events'))->toBeTrue();

        expect($harness->rollbackExactMigrations([$events]))->toBe([
            '2026_07_14_000014_create_partitioned_events_table',
        ])
            ->and($harness->hasTable('events'))->toBeFalse()
            ->and($harness->hasTable('events_default'))->toBeFalse()
            ->and($harness->countFunctions(['prevent_analytics_events_mutation']))->toBe(0)
            ->and($harness->countTriggers(['analytics_events_prevent_mutation_trigger']))->toBe(0)
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasTable('payments'))->toBeTrue()
            ->and($harness->hasTable('refunds'))->toBeTrue()
            ->and($harness->hasTable('download_grants'))->toBeTrue()
            ->and($harness->hasTable('download_logs'))->toBeTrue()
            ->and($harness->ranMigrations())->toHaveCount(29);
    } finally {
        $harness->drop();
    }

    expect(DB::connection('pgsql_migration')->table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});
