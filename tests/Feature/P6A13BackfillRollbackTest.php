<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-A1.3 rollback must restore the EXACT 000023 boundary: drop the six backfill
 * authorities, the run table and the runtime EXECUTE grants, while leaving the
 * P6-A1.2 pipeline, the P6-A1.1 authority and every earlier CRM phase untouched.
 */
it('rolls back to the exact 000023 boundary while preserving P6-A1.2, P6-A1.1 and earlier phases', function () {
    $frontier = '2026_07_14_000023_create_durable_crm_rollup_refresh_pipeline.php';
    $boundary = '2026_07_14_000024_create_crm_commerce_rollup_backfill_runs.php';

    $harness = new PhaseMigrationHarness('digitrove_p6a13_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($frontier);
        $pdo = $harness->ownerPdo();

        $tableExists = static fn (string $t): bool => $pdo->query("SELECT to_regclass('public.".$t."')")->fetchColumn() !== null;
        $functionExists = static fn (string $n): bool => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = '".$n."')")->fetchColumn();
        $triggerExists = static fn (string $n): bool => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname = '".$n."')")->fetchColumn();
        $fnPriv = static fn (string $role, string $sig): bool => (bool) $pdo->query("SELECT has_function_privilege('".$role."', 'public.".$sig."', 'EXECUTE')")->fetchColumn();

        $backfillFunctions = [
            'current_crm_commerce_rollup_backfill_high_water_mark',
            'list_crm_commerce_rollup_backfill_candidates',
            'start_crm_commerce_rollup_backfill',
            'get_crm_commerce_rollup_backfill_run',
            'process_crm_commerce_rollup_backfill_batch',
            'retry_crm_commerce_rollup_backfill_run',
        ];

        // ── 1. FRONTIER 000023 ── the 000023 boundary is exactly 39 migrations, and no
        //         P6-A1.3 object exists yet.
        expect($applied)->toHaveCount(39)
            ->and($tableExists('crm_commerce_rollup_refresh_outbox'))->toBeTrue()
            ->and($functionExists('process_crm_commerce_rollup_refresh'))->toBeTrue()
            ->and($tableExists('crm_commerce_rollup_backfill_runs'))->toBeFalse();

        foreach ($backfillFunctions as $function) {
            expect($functionExists($function))->toBeFalse();
        }

        // ── 2. APPLY 000024 ── run table, six authorities and runtime EXECUTE appear.
        $result = $harness->applyExactMigrations([$boundary]);
        expect($result)->toContain('2026_07_14_000024_create_crm_commerce_rollup_backfill_runs')
            ->and($tableExists('crm_commerce_rollup_backfill_runs'))->toBeTrue();

        foreach ($backfillFunctions as $function) {
            expect($functionExists($function))->toBeTrue();
        }

        expect($fnPriv('digitrove_runtime', 'process_crm_commerce_rollup_backfill_batch(bigint)'))->toBeTrue()
            // The P6-A1.2 enqueue authority is still forbidden to the runtime.
            ->and($fnPriv('digitrove_runtime', 'enqueue_crm_commerce_rollup_refresh(bigint, character varying)'))->toBeFalse();

        // ── 3. ROLLBACK 000024 ── every P6-A1.3 object and grant disappears.
        $output = $harness->rollbackExactMigrations([$boundary]);
        expect($output)->toContain('2026_07_14_000024_create_crm_commerce_rollup_backfill_runs')
            ->and($tableExists('crm_commerce_rollup_backfill_runs'))->toBeFalse();

        foreach ($backfillFunctions as $function) {
            expect($functionExists($function))->toBeFalse();
        }

        // ── The 000023 boundary and every earlier phase survive untouched. ──
        expect($tableExists('crm_commerce_rollup_refresh_outbox'))->toBeTrue()
            ->and($functionExists('enqueue_crm_commerce_rollup_refresh'))->toBeTrue()
            ->and($functionExists('list_due_crm_commerce_rollup_refreshes'))->toBeTrue()
            ->and($functionExists('process_crm_commerce_rollup_refresh'))->toBeTrue()
            ->and($triggerExists('crm_order_attributions_rollup_refresh_trigger'))->toBeTrue()
            ->and($triggerExists('refunds_rollup_refresh_trigger'))->toBeTrue()
            // P6-A1.1 authority.
            ->and($tableExists('crm_contact_commerce_rollups'))->toBeTrue()
            ->and($functionExists('refresh_crm_contact_commerce_rollup'))->toBeTrue()
            // P6-A1.0 / P6-A0.
            ->and($tableExists('crm_order_attributions'))->toBeTrue()
            ->and($tableExists('crm_order_attribution_outbox'))->toBeTrue()
            ->and($tableExists('crm_contacts'))->toBeTrue()
            ->and($functionExists('process_crm_order_attribution'))->toBeTrue()
            ->and($functionExists('resolve_crm_contact'))->toBeTrue()
            // Commerce and the roles are untouched.
            ->and($tableExists('orders'))->toBeTrue()
            ->and($tableExists('payments'))->toBeTrue()
            ->and($tableExists('refunds'))->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_crm_executor')")->fetchColumn())->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_runtime')")->fetchColumn())->toBeTrue();
    } finally {
        $harness->drop();
    }
});
