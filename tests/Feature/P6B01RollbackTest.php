<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-B0.1 rollback must restore the EXACT 000025 boundary: drop the seven read
 * authorities and their runtime grants, while leaving all of P6-A2 (four segment
 * tables, 18 functions, 3 triggers) and every earlier phase untouched.
 */
it('rolls back to the exact 000025 boundary while preserving P6-A2 and earlier phases', function () {
    $frontier = '2026_07_14_000025_create_typed_versioned_crm_segments.php';
    $boundary = '2026_07_14_000026_create_crm_admin_read_authorities.php';

    $harness = new PhaseMigrationHarness('digitrove_p6b01_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($frontier);
        $pdo = $harness->ownerPdo();

        // FAIL-CLOSED inventory read from pg_catalog, exactly like the P6-A2 hardening.
        $readAuthorities = static function () use ($pdo): array {
            return array_map('strval', $pdo->query(<<<'SQL'
                SELECT 'public.' || p.proname || '(' || pg_catalog.oidvectortypes(p.proargtypes) || ')'
                FROM pg_proc p
                JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = 'public'
                  AND (p.proname IN (
                        'list_crm_contacts',
                        'get_crm_contact',
                        'find_crm_contact_by_exact_email',
                        'list_crm_contact_consent_events',
                        'list_crm_contact_commerce_rollups',
                        'list_crm_contact_segment_memberships',
                        'list_crm_segment_versions'
                      ))
                ORDER BY 1
                SQL)->fetchAll(\PDO::FETCH_COLUMN));
        };

        $expected = [
            'public.find_crm_contact_by_exact_email(character varying)',
            'public.get_crm_contact(bigint)',
            'public.list_crm_contact_commerce_rollups(bigint)',
            'public.list_crm_contact_consent_events(bigint, bigint, integer)',
            'public.list_crm_contact_segment_memberships(bigint, bigint, integer)',
            'public.list_crm_contacts(bigint, character varying, character varying, integer)',
            'public.list_crm_segment_versions(bigint, integer, integer)',
        ];

        $tableExists = static fn (string $t): bool => $pdo->query("SELECT to_regclass('public.".$t."')")->fetchColumn() !== null;
        $functionExists = static fn (string $n): bool => (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = '".$n."')")->fetchColumn();
        $fnPriv = static fn (string $role, string $sig): bool => (bool) $pdo
            ->query("SELECT has_function_privilege('".$role."', to_regprocedure('".$sig."'), 'EXECUTE')")->fetchColumn();

        // ── 1. FRONTIER 000025 ── 41 migrations, P6-A2 present, no read authority yet.
        expect($applied)->toHaveCount(41)
            ->and($tableExists('crm_segments'))->toBeTrue()
            ->and($functionExists('list_crm_segment_current_members'))->toBeTrue()
            ->and($readAuthorities())->toBe([]);

        // ── 2. APPLY 000026 ── exactly the seven read authorities appear.
        $result = $harness->applyExactMigrations([$boundary]);
        expect($result)->toContain('2026_07_14_000026_create_crm_admin_read_authorities')
            ->and($readAuthorities())->toBe($expected)
            ->and($readAuthorities())->toHaveCount(7);

        foreach ($expected as $signature) {
            expect($fnPriv('digitrove_runtime', $signature))->toBeTrue()
                ->and((string) $pdo->query("SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = to_regprocedure('".$signature."')")->fetchColumn())
                ->toBe('digitrove_crm_executor');
        }

        // ── 3. ROLLBACK 000026 ── every read authority disappears.
        $output = $harness->rollbackExactMigrations([$boundary]);
        expect($output)->toContain('2026_07_14_000026_create_crm_admin_read_authorities')
            ->and($readAuthorities())->toBe([]);

        // ── P6-A2 and every earlier phase survive untouched. ──
        expect($tableExists('crm_segments'))->toBeTrue()
            ->and($tableExists('crm_segment_versions'))->toBeTrue()
            ->and($tableExists('crm_segment_generations'))->toBeTrue()
            ->and($tableExists('crm_segment_generation_members'))->toBeTrue()
            ->and($functionExists('validate_crm_segment_definition_v1'))->toBeTrue()
            ->and($functionExists('validate_crm_segment_definition_v1_int'))->toBeTrue()
            ->and($functionExists('validate_crm_segment_definition_v1_ts'))->toBeTrue()
            ->and($functionExists('crm_segment_contact_matches_v1'))->toBeTrue()
            ->and($functionExists('list_crm_segment_current_members'))->toBeTrue()
            // P6-A1.x / P6-A0.
            ->and($tableExists('crm_contacts'))->toBeTrue()
            ->and($tableExists('crm_marketing_consent_events'))->toBeTrue()
            ->and($tableExists('crm_contact_commerce_rollups'))->toBeTrue()
            ->and($functionExists('refresh_crm_contact_commerce_rollup'))->toBeTrue()
            ->and($functionExists('resolve_crm_contact'))->toBeTrue()
            // Commerce.
            ->and($tableExists('orders'))->toBeTrue()
            ->and($tableExists('payments'))->toBeTrue()
            ->and($tableExists('refunds'))->toBeTrue()
            // The 000025 frontier is restored exactly.
            ->and($harness->ranMigrations())->toHaveCount(41);
    } finally {
        $harness->drop();
    }
});
