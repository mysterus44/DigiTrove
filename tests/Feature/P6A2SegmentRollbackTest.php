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
        // FAIL-CLOSED P6-A2 function inventory: pg_catalog must expose EXACTLY these 18
        // typed signatures after UP, and NONE after DOWN.
        //
        // The proof does not rely on this hand-written list alone. `$actualSignatures`
        // below reads the real set from pg_catalog and is compared for SET EQUALITY, so
        // a 19th P6-A2 function omitted from both the migration inventory and this list
        // still turns the test red (19 actual != 18 expected).
        //
        // 11 runtime authorities + 4 internal (validator, its two typing helpers, the
        // matcher) + 3 trigger functions = 18.
        $segmentSignatures = [
            // Runtime authorities.
            'public.create_crm_segment(character varying)',
            'public.create_crm_segment_version(bigint, jsonb)',
            'public.publish_crm_segment_version(bigint)',
            'public.get_crm_segment(bigint)',
            'public.list_crm_segments(bigint, integer)',
            'public.start_crm_segment_generation(bigint, integer)',
            'public.process_crm_segment_generation_batch(bigint)',
            'public.retry_crm_segment_generation(bigint)',
            'public.get_crm_segment_generation(bigint)',
            'public.list_due_crm_segment_generations(integer)',
            'public.list_crm_segment_current_members(bigint, bigint, integer)',
            // Internal — never callable by the runtime. The two typing helpers are the
            // objects a previous version of this test failed to notice.
            'public.validate_crm_segment_definition_v1(jsonb)',
            'public.validate_crm_segment_definition_v1_int(jsonb)',
            'public.validate_crm_segment_definition_v1_ts(jsonb)',
            'public.crm_segment_contact_matches_v1(bigint, jsonb)',
            // Trigger functions.
            'public.enforce_crm_segment_version_immutability()',
            'public.enforce_crm_segment_generation_immutability()',
            'public.enforce_crm_segment_generation_member_immutability()',
        ];

        sort($segmentSignatures);

        // The REAL set of P6-A2 functions, read from pg_catalog rather than declared.
        //
        // Naming predicate: every function 000025 creates carries `crm_segment` in its
        // proname, and no earlier phase (P6-A0/A1.0/A1.1/A1.2/A1.3) creates any such
        // function — asserted below by the empty set at the 000024 frontier. Filtering
        // by owner would be wrong: digitrove_crm_executor also owns P6-A0/A1 functions.
        $actualSignatures = static function () use ($pdo): array {
            // oidvectortypes() yields the ARGUMENT TYPES only; identity_arguments would
            // also carry the parameter names (`p_generation_id bigint`).
            $rows = $pdo->query(<<<'SQL'
                SELECT 'public.' || p.proname || '(' || pg_catalog.oidvectortypes(p.proargtypes) || ')' AS signature
                FROM pg_proc p
                JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = 'public'
                  AND p.proname LIKE '%crm_segment%'
                ORDER BY signature
                SQL)->fetchAll(PDO::FETCH_COLUMN);

            return array_map('strval', $rows);
        };

        // Resolves a typed signature to an OID, or null when the function is absent.
        $procExists = static fn (string $signature): bool => $pdo
            ->query("SELECT to_regprocedure('".$signature."')")
            ->fetchColumn() !== null;

        $procOwner = static fn (string $signature): string => (string) $pdo
            ->query("SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE oid = to_regprocedure('".$signature."')")
            ->fetchColumn();

        $procPriv = static fn (string $role, string $signature): bool => (bool) $pdo
            ->query("SELECT has_function_privilege('".$role."', to_regprocedure('".$signature."'), 'EXECUTE')")
            ->fetchColumn();

        $publicMayExecute = static fn (string $signature): bool => (bool) $pdo
            ->query("SELECT COALESCE((SELECT bool_or(a.privilege_type = 'EXECUTE') FROM pg_proc p CROSS JOIN LATERAL aclexplode(p.proacl) a WHERE p.oid = to_regprocedure('".$signature."') AND a.grantee = 0), FALSE)")
            ->fetchColumn();

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
        // NOT ONE of the 18 signatures may exist at the 000024 frontier — including the
        // two typing helpers.
        foreach ($segmentSignatures as $signature) {
            expect($procExists($signature))->toBeFalse();
        }

        // 0 — and this also validates the naming predicate itself: no earlier phase
        // owns a function matching it.
        expect($actualSignatures())->toBe([]);

        // ── 2. APPLY 000025 ── tables, authorities and runtime grants appear.
        $result = $harness->applyExactMigrations([$boundary]);
        expect($result)->toContain('2026_07_14_000025_create_typed_versioned_crm_segments');

        foreach ($segmentTables as $table) {
            expect($tableExists($table))->toBeTrue();
        }
        foreach ($segmentFunctions as $function) {
            expect($functionExists($function))->toBeTrue();
        }

        // 18 — SET EQUALITY against pg_catalog, not mere inclusion. An extra P6-A2
        // function that nobody inventoried makes this fail (19 != 18), which is what
        // makes the guard genuinely fail-closed.
        expect($actualSignatures())->toBe($segmentSignatures)
            ->and($actualSignatures())->toHaveCount(18);

        // Every one of the 18 exists, is owned by the restricted executor, and is
        // closed to PUBLIC.
        foreach ($segmentSignatures as $signature) {
            expect($procExists($signature))->toBeTrue()
                ->and($procOwner($signature))->toBe('digitrove_crm_executor')
                ->and($publicMayExecute($signature))->toBeFalse();
        }

        // The internal validator, its typing helpers and the matcher stay unreachable
        // from the runtime; the 11 bounded authorities are reachable.
        foreach ([
            'public.validate_crm_segment_definition_v1(jsonb)',
            'public.validate_crm_segment_definition_v1_int(jsonb)',
            'public.validate_crm_segment_definition_v1_ts(jsonb)',
            'public.crm_segment_contact_matches_v1(bigint, jsonb)',
        ] as $internal) {
            expect($procPriv('digitrove_runtime', $internal))->toBeFalse();
        }
        expect($procPriv('digitrove_runtime', 'public.process_crm_segment_generation_batch(bigint)'))->toBeTrue();

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

        // 0 — THE guard. Read from pg_catalog, so it covers the two leaked helpers, the
        // trigger functions, and any future P6-A2 function nobody remembered to drop.
        expect($actualSignatures())->toBe([]);

        // Kept for a precise regression message when a known signature survives.
        $surviving = array_values(array_filter($segmentSignatures, $procExists));
        expect($surviving)->toBe([]);

        // No P6-A2 trigger and no P6-A2 table-owned index survives either.
        expect((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname LIKE 'crm_segment%')")->fetchColumn())->toBeFalse()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_indexes WHERE indexname LIKE 'crm_segment%')")->fetchColumn())->toBeFalse()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conname LIKE 'crm_segment%')")->fetchColumn())->toBeFalse();

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

        // The P6-A1.x authorities survive with their EXACT signatures.
        foreach ([
            'public.refresh_crm_contact_commerce_rollup(bigint, character varying)',
            'public.enqueue_crm_commerce_rollup_refresh(bigint, character varying)',
            'public.process_crm_commerce_rollup_refresh(bigint, character varying)',
            'public.list_due_crm_commerce_rollup_refreshes(integer)',
            'public.current_crm_commerce_rollup_backfill_high_water_mark()',
            'public.process_crm_commerce_rollup_backfill_batch(bigint)',
            'public.resolve_crm_contact(character varying, character varying, bigint, bigint)',
        ] as $preserved) {
            expect($procExists($preserved))->toBeTrue();
        }

        // The 000024 frontier is restored exactly: 40 applied migrations.
        expect($harness->ranMigrations())->toHaveCount(40);
    } finally {
        $harness->drop();
    }
});
