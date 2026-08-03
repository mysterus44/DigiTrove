<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

it('rolls back only P6-A0 while preserving P0 through P5 and the cluster role', function () {
    $boundary = '2026_07_14_000020_create_crm_identity_and_consent_foundation.php';
    $harness = new PhaseMigrationHarness('digitrove_p6a0_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($boundary);

        expect($applied)->toHaveCount(36)
            ->and(end($applied))->toBe('2026_07_14_000020_create_crm_identity_and_consent_foundation')
            ->and($harness->hasTable('crm_contacts'))->toBeTrue()
            ->and($harness->hasTable('crm_marketing_consent_events'))->toBeTrue()
            ->and($harness->countFunctions([
                'resolve_crm_contact',
                'record_crm_marketing_consent',
                'has_current_marketing_consent',
                'enforce_crm_contacts_integrity',
                'prevent_crm_marketing_consent_event_mutation',
            ]))->toBe(5)
            ->and($harness->countTriggers([
                'crm_contacts_integrity_trigger',
                'crm_marketing_consent_events_append_only_trigger',
            ]))->toBe(2);

        expect($harness->rollbackExactMigrations([$boundary]))->toBe([
            '2026_07_14_000020_create_crm_identity_and_consent_foundation',
        ])
            ->and($harness->hasTable('crm_contacts'))->toBeFalse()
            ->and($harness->hasTable('crm_marketing_consent_events'))->toBeFalse()
            ->and($harness->countFunctions([
                'resolve_crm_contact',
                'record_crm_marketing_consent',
                'has_current_marketing_consent',
                'enforce_crm_contacts_integrity',
                'prevent_crm_marketing_consent_event_mutation',
            ]))->toBe(0)
            ->and($harness->countTriggers([
                'crm_contacts_integrity_trigger',
                'crm_marketing_consent_events_append_only_trigger',
            ]))->toBe(0)
            ->and($harness->hasTable('events'))->toBeTrue()
            ->and($harness->hasTable('analytics_sessions'))->toBeTrue()
            ->and($harness->hasTable('daily_sales_stats'))->toBeTrue()
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasTable('download_grants'))->toBeTrue()
            ->and($harness->ranMigrations())->toHaveCount(35);
    } finally {
        $harness->drop();
    }

    $owner = DB::connection('pgsql_migration');
    expect($owner->table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse()
        ->and($owner->table('pg_roles')->where('rolname', 'digitrove_crm_executor')->exists())->toBeTrue();
});
