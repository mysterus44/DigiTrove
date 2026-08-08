<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-A2 rollback must restore the EXACT 000024 boundary: drop the four segment tables,
 * every segment authority and the runtime grants, while leaving P6-A1.3 → P6-A0,
 * Commerce, Analytics and the roles untouched.
 */
it('rolls back to the exact 000024 boundary while preserving every earlier phase', function () {
    $frontier = '2026_07_14_000024_create_crm_commerce_rollup_backfill_runs.php';
    $boundary = '2026_07_14_000025_create_typed_versioned_crm_segments.php';

    $harness = new PhaseMigrationHarness('digitrove_p6a2_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($frontier);
        $pdo = $harness->ownerPdo();

        $tableExists = static fn (string $t): bool => $pdo->query("SELECT to_regclass('public.".$t."')")->fetchColumn() !== null;
        $functionExists = static fn (string $n): bool => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = '".$n."')")->fetchColumn();
        $fnPriv = static fn (string $role, string $sig): bool => (bool) $pdo->query("SELECT has_function_privilege('".$role."', 'public.".$sig."', 'EXECUTE')")->fetchColumn();

        $segmentTables = [
            'crm_segments',
            'crm_segment_versions',
            'crm_segment_generations',
            'crm_segment_generation_members',
        ];
        $segmentFunctions = [
            'validate_crm_segment_definition_v1',
            'crm_segment_contact_matches_v1',
            'create_crm_segment',
            'create_crm_segment_version',
            'publish_crm_segment_version',
            'start_crm_segment_generation',
            'process_crm_segment_generation_batch',
            'retry_crm_segment_generation',
            'get_crm_segment',
            'list_crm_segments',
            'get_crm_segment_generation',
            'list_due_crm_segment_generations',
            'list_crm_segment_current_members',
        ];

        // ── 1. FRONTIER 000024 ── 40 migrations, no P6-A2 object yet.
        expect($applied)->toHaveCount(40)
            ->and($tableExists('crm_commerce_rollup_backfill_runs'))->toBeTrue();

        foreach ($segmentTables as $table) {
            expect($tableExists($table))->toBeFalse();
        }
        foreach ($segmentFunctions as $function) {
            expect($functionExists($function))->toBeFalse();
        }

        // ── 2. APPLY 000025 ── tables, authorities and runtime grants appear.
        $result = $harness->applyExactMigrations([$boundary]);
        expect($result)->toContain('2026_07_14_000025_create_typed_versioned_crm_segments');

        foreach ($segmentTables as $table) {
            expect($tableExists($table))->toBeTrue();
        }
        foreach ($segmentFunctions as $function) {
            expect($functionExists($function))->toBeTrue();
        }

        expect($fnPriv('digitrove_runtime', 'process_crm_segment_generation_batch(bigint)'))->toBeTrue()
            // The internal matcher stays out of reach even while P6-A2 is installed.
            ->and($fnPriv('digitrove_runtime', 'crm_segment_contact_matches_v1(bigint, jsonb)'))->toBeFalse();

        // ── 3. ROLLBACK 000025 ── every P6-A2 object disappears.
        $output = $harness->rollbackExactMigrations([$boundary]);
        expect($output)->toContain('2026_07_14_000025_create_typed_versioned_crm_segments');

        foreach ($segmentTables as $table) {
            expect($tableExists($table))->toBeFalse();
        }
        foreach ($segmentFunctions as $function) {
            expect($functionExists($function))->toBeFalse();
        }

        // ── Every earlier phase survives untouched. ──
        expect($tableExists('crm_commerce_rollup_backfill_runs'))->toBeTrue()
            ->and($functionExists('process_crm_commerce_rollup_backfill_batch'))->toBeTrue()
            // P6-A1.2 / P6-A1.1.
            ->and($tableExists('crm_commerce_rollup_refresh_outbox'))->toBeTrue()
            ->and($functionExists('process_crm_commerce_rollup_refresh'))->toBeTrue()
            ->and($tableExists('crm_contact_commerce_rollups'))->toBeTrue()
            ->and($functionExists('refresh_crm_contact_commerce_rollup'))->toBeTrue()
            // P6-A1.0 / P6-A0.
            ->and($tableExists('crm_order_attributions'))->toBeTrue()
            ->and($tableExists('crm_contacts'))->toBeTrue()
            ->and($tableExists('crm_marketing_consent_events'))->toBeTrue()
            ->and($functionExists('resolve_crm_contact'))->toBeTrue()
            // Commerce, Analytics and the roles.
            ->and($tableExists('orders'))->toBeTrue()
            ->and($tableExists('payments'))->toBeTrue()
            ->and($tableExists('refunds'))->toBeTrue()
            ->and($tableExists('events'))->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_crm_executor')")->fetchColumn())->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_runtime')")->fetchColumn())->toBeTrue();
    } finally {
        $harness->drop();
    }
});
