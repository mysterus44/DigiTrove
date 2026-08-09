<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-B1 rollback must restore the EXACT 000026 boundary: drop `crm_exports` and the ten
 * export authorities with their runtime grants, while leaving all of P6-B0.1, P6-A2 and
 * every earlier phase untouched.
 *
 * The inventory is read from pg_catalog and asserted as an exact list, so a function
 * that is created but never dropped fails the test rather than silently surviving.
 */
it('rolls back to the exact 000026 boundary while preserving P6-B0.1 and earlier phases', function () {
    $frontier = '2026_07_14_000026_create_crm_admin_read_authorities.php';
    $boundary = '2026_07_14_000027_create_crm_exports_table.php';

    $harness = new PhaseMigrationHarness('digitrove_p6b1_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($frontier);
        $pdo = $harness->ownerPdo();

        $exportAuthorities = static function () use ($pdo): array {
            return array_map('strval', $pdo->query(<<<'SQL'
                SELECT 'public.' || p.proname || '(' || pg_catalog.oidvectortypes(p.proargtypes) || ')'
                FROM pg_proc p
                JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = 'public'
                  AND p.proname IN (
                        'create_crm_export',
                        'claim_crm_export',
                        'complete_crm_export',
                        'fail_crm_export',
                        'get_crm_export',
                        'list_crm_exports',
                        'list_due_crm_exports',
                        'expire_crm_exports',
                        'list_crm_export_contact_rows',
                        'list_crm_export_member_rows'
                      )
                ORDER BY 1
                SQL)->fetchAll(PDO::FETCH_COLUMN));
        };

        $expected = [
            'public.claim_crm_export(bigint)',
            'public.complete_crm_export(bigint, bigint, character varying, character varying, bigint, character varying)',
            'public.create_crm_export(character varying, bigint, bigint, integer, integer)',
            'public.expire_crm_exports(integer)',
            'public.fail_crm_export(bigint, character varying, character varying)',
            'public.get_crm_export(bigint)',
            'public.list_crm_export_contact_rows(bigint, integer)',
            'public.list_crm_export_member_rows(bigint, bigint, integer)',
            'public.list_crm_exports(bigint, integer)',
            'public.list_due_crm_exports(integer)',
        ];

        $tableExists = static fn (string $t): bool => $pdo->query("SELECT to_regclass('public.".$t."')")->fetchColumn() !== null;
        $functionExists = static fn (string $n): bool => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = '".$n."')")->fetchColumn();
        $fnPriv = static fn (string $role, string $sig): bool => (bool) $pdo
            ->query("SELECT has_function_privilege('".$role."', to_regprocedure('".$sig."'), 'EXECUTE')")->fetchColumn();

        // ── 1. FRONTIER 000026 ── 42 migrations, B0.1 present, no export object yet.
        expect($applied)->toHaveCount(42)
            ->and($functionExists('list_crm_contacts'))->toBeTrue()
            ->and($tableExists('crm_exports'))->toBeFalse()
            ->and($exportAuthorities())->toBe([]);

        // ── 2. APPLY 000027 ── the table and exactly the ten authorities appear.
        $result = $harness->applyExactMigrations([$boundary]);
        expect($result)->toContain('2026_07_14_000027_create_crm_exports_table')
            ->and($tableExists('crm_exports'))->toBeTrue()
            ->and($exportAuthorities())->toBe($expected)
            ->and($exportAuthorities())->toHaveCount(10);

        foreach ($expected as $signature) {
            expect($fnPriv('digitrove_runtime', $signature))->toBeTrue()
                ->and((string) $pdo->query("SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = to_regprocedure('".$signature."')")->fetchColumn())
                ->toBe('digitrove_crm_executor');
        }

        // The table itself is owned by the executor and unreachable from the runtime.
        expect((string) $pdo->query("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE relname = 'crm_exports'")->fetchColumn())
            ->toBe('digitrove_crm_executor')
            ->and((bool) $pdo->query("SELECT has_table_privilege('digitrove_runtime', 'public.crm_exports', 'SELECT')")->fetchColumn())
            ->toBeFalse();

        // ── 3. ROLLBACK 000027 ── every export object disappears.
        $output = $harness->rollbackExactMigrations([$boundary]);
        expect($output)->toContain('2026_07_14_000027_create_crm_exports_table')
            ->and($exportAuthorities())->toBe([])
            ->and($tableExists('crm_exports'))->toBeFalse();

        // ── P6-B0.1 and every earlier phase survive untouched. ──
        expect($functionExists('list_crm_contacts'))->toBeTrue()
            ->and($functionExists('get_crm_contact'))->toBeTrue()
            ->and($functionExists('find_crm_contact_by_exact_email'))->toBeTrue()
            ->and($functionExists('list_crm_contact_consent_events'))->toBeTrue()
            ->and($functionExists('list_crm_contact_commerce_rollups'))->toBeTrue()
            ->and($functionExists('list_crm_contact_segment_memberships'))->toBeTrue()
            ->and($functionExists('list_crm_segment_versions'))->toBeTrue()
            // P6-A2.
            ->and($tableExists('crm_segments'))->toBeTrue()
            ->and($tableExists('crm_segment_generation_members'))->toBeTrue()
            ->and($functionExists('validate_crm_segment_definition_v1'))->toBeTrue()
            // P6-A0 / A1.x.
            ->and($tableExists('crm_contacts'))->toBeTrue()
            ->and($tableExists('crm_contact_commerce_rollups'))->toBeTrue()
            ->and($functionExists('refresh_crm_contact_commerce_rollup'))->toBeTrue()
            // Commerce and identity.
            ->and($tableExists('orders'))->toBeTrue()
            ->and($tableExists('users'))->toBeTrue()
            // The 000026 frontier is restored exactly.
            ->and($harness->ranMigrations())->toHaveCount(42);
    } finally {
        $harness->drop();
    }
});
