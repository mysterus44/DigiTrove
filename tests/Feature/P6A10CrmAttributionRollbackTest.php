<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

it('rolls back only P6-A1.0 while preserving P6-A0 and earlier phases', function () {
    $boundary = '2026_07_14_000021_create_durable_crm_order_attribution_pipeline.php';
    $harness = new PhaseMigrationHarness('digitrove_p6a10_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($boundary);

        expect($applied)->toHaveCount(37)
            ->and(end($applied))->toBe('2026_07_14_000021_create_durable_crm_order_attribution_pipeline')
            ->and($harness->hasTable('crm_order_attribution_outbox'))->toBeTrue()
            ->and($harness->hasTable('crm_order_attributions'))->toBeTrue()
            ->and($harness->countFunctions([
                'enforce_crm_order_attribution_outbox_integrity',
                'prevent_crm_order_attribution_mutation',
                'enqueue_crm_order_attribution',
                'list_due_crm_order_attributions',
                'process_crm_order_attribution',
            ]))->toBe(5)
            ->and($harness->countTriggers([
                'crm_order_attribution_outbox_integrity_trigger',
                'crm_order_attributions_immutable_trigger',
                'orders_enqueue_crm_attribution_trigger',
            ]))->toBe(3);

        expect($harness->rollbackExactMigrations([$boundary]))->toBe([
            '2026_07_14_000021_create_durable_crm_order_attribution_pipeline',
        ])
            ->and($harness->hasTable('crm_order_attribution_outbox'))->toBeFalse()
            ->and($harness->hasTable('crm_order_attributions'))->toBeFalse()
            ->and($harness->countFunctions([
                'enforce_crm_order_attribution_outbox_integrity',
                'prevent_crm_order_attribution_mutation',
                'enqueue_crm_order_attribution',
                'list_due_crm_order_attributions',
                'process_crm_order_attribution',
            ]))->toBe(0)
            ->and($harness->countTriggers([
                'crm_order_attribution_outbox_integrity_trigger',
                'crm_order_attributions_immutable_trigger',
                'orders_enqueue_crm_attribution_trigger',
            ]))->toBe(0)
            ->and($harness->hasTable('crm_contacts'))->toBeTrue()
            ->and($harness->hasTable('crm_marketing_consent_events'))->toBeTrue()
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasTable('refunds'))->toBeTrue()
            ->and($harness->ranMigrations())->toHaveCount(36);
    } finally {
        $harness->drop();
    }

    expect(DB::connection('pgsql_migration')->table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse()
        ->and(DB::connection('pgsql_migration')->table('pg_roles')->where('rolname', 'digitrove_crm_executor')->exists())->toBeTrue();
});
