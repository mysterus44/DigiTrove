<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

const P5A3_READER_MIGRATION = '2026_07_14_000019_grant_analytics_dashboard_read_privileges.php';

it('rolls back only the reader ACL while preserving every analytics projection', function () {
    $harness = new PhaseMigrationHarness('digitrove_p5a3_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough(P5A3_READER_MIGRATION);

        expect($applied)->toHaveCount(35)
            ->and(end($applied))->toBe('2026_07_14_000019_grant_analytics_dashboard_read_privileges');

        $reader = $harness->analyticsReaderPdo();
        foreach (P5A3_ROLLUPS as $table) {
            expect((int) $reader->query("SELECT count(*) FROM public.{$table}")->fetchColumn())->toBeGreaterThanOrEqual(0);
        }

        expect($harness->rollbackExactMigrations([P5A3_READER_MIGRATION]))->toBe([
            '2026_07_14_000019_grant_analytics_dashboard_read_privileges',
        ]);

        foreach (P5A3_ROLLUPS as $table) {
            $state = null;
            try {
                $reader->query("SELECT count(*) FROM public.{$table}");
            } catch (PDOException $exception) {
                $state = (string) $exception->getCode();
            }
            expect($state)->toBe('42501')
                ->and($harness->hasTable($table))->toBeTrue();
        }

        expect($harness->ownerPdo()->query(
            "SELECT count(*) FROM pg_roles WHERE rolname = 'digitrove_analytics_reader'",
        )->fetchColumn())->toBe(1)
            ->and($harness->ranMigrations())->toHaveCount(34);
    } finally {
        $harness->drop();
    }

    expect(DB::connection('pgsql_migration')->table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});
