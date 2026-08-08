<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-A1.1 rollback must restore the EXACT 000021 privilege boundary.
 *
 * up() creates the rollup table + SECURITY DEFINER refresh function AND grants
 * digitrove_crm_executor SELECT on payments/refunds. A correct down() therefore
 * has to revoke those two grants in addition to dropping the objects — otherwise
 * the executor keeps commerce-read privileges after the phase is rolled back
 * (D-046.1). This test proves the transition across three points:
 *   1. frontier 000021 — no grants, no objects;
 *   2. after 000022    — grants present, objects present, runtime/PUBLIC still shut out;
 *   3. after rollback  — grants revoked, objects gone, earlier phases untouched.
 */
it('rolls back to the exact 000021 ACL boundary while preserving P6-A1.0 and earlier phases', function () {
    $frontier = '2026_07_14_000021_create_durable_crm_order_attribution_pipeline.php';
    $boundary = '2026_07_14_000022_create_crm_contact_commerce_rollups.php';

    $harness = new PhaseMigrationHarness('digitrove_p6a11_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough($frontier);
        $pdo = $harness->ownerPdo();

        // Catalog-level helpers. has_table_privilege / has_function_privilege take a
        // concrete role name; PUBLIC (grantee OID 0) is proved absent via aclexplode.
        $tablePriv = static fn (string $role, string $table, string $priv): bool => (bool) $pdo
            ->query("SELECT has_table_privilege('".$role."', 'public.".$table."', '".$priv."')")
            ->fetchColumn();

        $functionPriv = static fn (string $role, string $signature, string $priv): bool => (bool) $pdo
            ->query("SELECT has_function_privilege('".$role."', 'public.".$signature."', '".$priv."')")
            ->fetchColumn();

        $tableExists = static fn (string $table): bool => $pdo
            ->query("SELECT to_regclass('public.".$table."')")
            ->fetchColumn() !== null;

        $functionExists = static fn (string $name): bool => (bool) $pdo
            ->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = '".$name."')")
            ->fetchColumn();

        $roleExists = static fn (string $role): bool => (bool) $pdo
            ->query("SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname = '".$role."')")
            ->fetchColumn();

        $publicHasAnyGrant = static fn (string $table): bool => (bool) $pdo
            ->query(
                'SELECT EXISTS(SELECT 1 FROM pg_class c '
                .'CROSS JOIN LATERAL aclexplode(c.relacl) a '
                ."WHERE c.oid = 'public.".$table."'::regclass AND a.grantee = 0)"
            )
            ->fetchColumn();

        $fnSignature = 'refresh_crm_contact_commerce_rollup(bigint, character varying)';

        // ── 1. FRONTIER 000021 ── executor exists but holds no commerce-read grant,
        //         and the P6-A1.1 objects do not exist yet.
        expect($roleExists('digitrove_crm_executor'))->toBeTrue()
            ->and($tablePriv('digitrove_crm_executor', 'payments', 'SELECT'))->toBeFalse()
            ->and($tablePriv('digitrove_crm_executor', 'refunds', 'SELECT'))->toBeFalse()
            ->and($functionExists('refresh_crm_contact_commerce_rollup'))->toBeFalse()
            ->and($tableExists('crm_contact_commerce_rollups'))->toBeFalse();

        // ── 2. APPLY 000022 ── grants and objects appear; runtime and PUBLIC stay shut out.
        $applied = $harness->applyExactMigrations([$boundary]);
        expect($applied)->toContain('2026_07_14_000022_create_crm_contact_commerce_rollups');

        expect($tablePriv('digitrove_crm_executor', 'payments', 'SELECT'))->toBeTrue()
            ->and($tablePriv('digitrove_crm_executor', 'refunds', 'SELECT'))->toBeTrue()
            ->and($functionExists('refresh_crm_contact_commerce_rollup'))->toBeTrue()
            ->and($tableExists('crm_contact_commerce_rollups'))->toBeTrue()
            // runtime never touches the projection nor the authority.
            ->and($tablePriv('digitrove_runtime', 'crm_contact_commerce_rollups', 'SELECT'))->toBeFalse()
            ->and($functionPriv('digitrove_runtime', $fnSignature, 'EXECUTE'))->toBeFalse()
            // PUBLIC holds no privilege on the projection table.
            ->and($publicHasAnyGrant('crm_contact_commerce_rollups'))->toBeFalse();

        // ── 3. ROLLBACK 000022 ── grants revoked, objects gone, earlier phases intact.
        $output = $harness->rollbackExactMigrations([$boundary]);
        expect($output)->toContain('2026_07_14_000022_create_crm_contact_commerce_rollups');

        // The revoke is the whole point of the fix: without it the executor keeps SELECT.
        expect($tablePriv('digitrove_crm_executor', 'payments', 'SELECT'))->toBeFalse()
            ->and($tablePriv('digitrove_crm_executor', 'refunds', 'SELECT'))->toBeFalse()
            ->and($functionExists('refresh_crm_contact_commerce_rollup'))->toBeFalse()
            ->and($tableExists('crm_contact_commerce_rollups'))->toBeFalse();

        // Executor role and every earlier CRM phase survive the rollback untouched.
        expect($roleExists('digitrove_crm_executor'))->toBeTrue()
            // P6-A1.0 tables.
            ->and($tableExists('crm_order_attributions'))->toBeTrue()
            ->and($tableExists('crm_order_attribution_outbox'))->toBeTrue()
            // P6-A0 tables.
            ->and($tableExists('crm_contacts'))->toBeTrue()
            ->and($tableExists('crm_marketing_consent_events'))->toBeTrue()
            // P6-A0 functions.
            ->and($functionExists('resolve_crm_contact'))->toBeTrue()
            ->and($functionExists('record_crm_marketing_consent'))->toBeTrue()
            // P6-A1.0 functions.
            ->and($functionExists('enqueue_crm_order_attribution'))->toBeTrue()
            ->and($functionExists('process_crm_order_attribution'))->toBeTrue();
    } finally {
        $harness->drop();
    }
});
