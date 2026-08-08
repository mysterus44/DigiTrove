<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-A1.2 rollback must restore the EXACT 000022 boundary: drop the outbox, the five
 * orchestration functions, the two source triggers and the runtime EXECUTE grants —
 * while leaving the P6-A1.1 authority (refresh + rollup table) and every earlier CRM
 * phase untouched. Proven across three points: before 000023, after 000023, after down.
 */
it('rolls back to the exact 000022 boundary while preserving P6-A1.1 and earlier phases', function () {
    $frontier = '2026_07_14_000022_create_crm_contact_commerce_rollups.php';
    $boundary = '2026_07_14_000023_create_durable_crm_rollup_refresh_pipeline.php';

    $harness = new PhaseMigrationHarness('digitrove_p6a12_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough($frontier);
        $pdo = $harness->ownerPdo();

        $tableExists = static fn (string $t): bool => $pdo->query("SELECT to_regclass('public.".$t."')")->fetchColumn() !== null;
        $functionExists = static fn (string $n): bool => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = '".$n."')")->fetchColumn();
        $triggerExists = static fn (string $n): bool => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname = '".$n."')")->fetchColumn();
        $fnPriv = static fn (string $role, string $sig): bool => (bool) $pdo->query("SELECT has_function_privilege('".$role."', 'public.".$sig."', 'EXECUTE')")->fetchColumn();
        $tablePriv = static fn (string $role, string $t, string $p): bool => (bool) $pdo->query("SELECT has_table_privilege('".$role."', 'public.".$t."', '".$p."')")->fetchColumn();

        $listDue = 'list_due_crm_commerce_rollup_refreshes(integer)';
        $process = 'process_crm_commerce_rollup_refresh(bigint, character varying)';

        // ── 1. FRONTIER 000022 ── P6-A1.1 authority present; no P6-A1.2 objects yet.
        expect($tableExists('crm_contact_commerce_rollups'))->toBeTrue()
            ->and($functionExists('refresh_crm_contact_commerce_rollup'))->toBeTrue()
            ->and($tablePriv('digitrove_crm_executor', 'payments', 'SELECT'))->toBeTrue()
            ->and($tablePriv('digitrove_crm_executor', 'refunds', 'SELECT'))->toBeTrue()
            ->and($tableExists('crm_commerce_rollup_refresh_outbox'))->toBeFalse()
            ->and($functionExists('enqueue_crm_commerce_rollup_refresh'))->toBeFalse()
            ->and($functionExists('process_crm_commerce_rollup_refresh'))->toBeFalse();

        // ── 2. APPLY 000023 ── outbox, functions, triggers and runtime EXECUTE appear.
        $applied = $harness->applyExactMigrations([$boundary]);
        expect($applied)->toContain('2026_07_14_000023_create_durable_crm_rollup_refresh_pipeline');

        expect($tableExists('crm_commerce_rollup_refresh_outbox'))->toBeTrue()
            ->and($functionExists('enqueue_crm_commerce_rollup_refresh'))->toBeTrue()
            ->and($functionExists('list_due_crm_commerce_rollup_refreshes'))->toBeTrue()
            ->and($functionExists('process_crm_commerce_rollup_refresh'))->toBeTrue()
            ->and($triggerExists('crm_order_attributions_rollup_refresh_trigger'))->toBeTrue()
            ->and($triggerExists('refunds_rollup_refresh_trigger'))->toBeTrue()
            ->and($fnPriv('digitrove_runtime', $listDue))->toBeTrue()
            ->and($fnPriv('digitrove_runtime', $process))->toBeTrue()
            ->and($fnPriv('digitrove_runtime', 'enqueue_crm_commerce_rollup_refresh(bigint, character varying)'))->toBeFalse()
            ->and($tablePriv('digitrove_runtime', 'crm_commerce_rollup_refresh_outbox', 'SELECT'))->toBeFalse();

        // ── 3. ROLLBACK 000023 ── every P6-A1.2 object and grant is gone.
        $output = $harness->rollbackExactMigrations([$boundary]);
        expect($output)->toContain('2026_07_14_000023_create_durable_crm_rollup_refresh_pipeline');

        expect($tableExists('crm_commerce_rollup_refresh_outbox'))->toBeFalse()
            ->and($functionExists('enqueue_crm_commerce_rollup_refresh'))->toBeFalse()
            ->and($functionExists('list_due_crm_commerce_rollup_refreshes'))->toBeFalse()
            ->and($functionExists('process_crm_commerce_rollup_refresh'))->toBeFalse()
            ->and($triggerExists('crm_order_attributions_rollup_refresh_trigger'))->toBeFalse()
            ->and($triggerExists('refunds_rollup_refresh_trigger'))->toBeFalse();
        // The functions no longer exist, so runtime holds no EXECUTE on them either
        // (has_function_privilege is not used here: it errors on a missing function).

        // ── The 000022 boundary and every earlier CRM phase survive untouched. ──
        expect($tableExists('crm_contact_commerce_rollups'))->toBeTrue()
            ->and($functionExists('refresh_crm_contact_commerce_rollup'))->toBeTrue()
            ->and($tablePriv('digitrove_crm_executor', 'payments', 'SELECT'))->toBeTrue()
            ->and($tablePriv('digitrove_crm_executor', 'refunds', 'SELECT'))->toBeTrue()
            // P6-A1.0.
            ->and($tableExists('crm_order_attributions'))->toBeTrue()
            ->and($tableExists('crm_order_attribution_outbox'))->toBeTrue()
            ->and($functionExists('enqueue_crm_order_attribution'))->toBeTrue()
            ->and($functionExists('process_crm_order_attribution'))->toBeTrue()
            // P6-A0.
            ->and($tableExists('crm_contacts'))->toBeTrue()
            ->and($functionExists('resolve_crm_contact'))->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_crm_executor')")->fetchColumn())->toBeTrue();
    } finally {
        $harness->drop();
    }
});
