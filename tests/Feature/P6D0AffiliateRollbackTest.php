<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-D0 rollback must restore the EXACT `000028` boundary, then re-apply cleanly.
 *
 * The inventory is read from `pg_catalog` as an exact list rather than spot-checked, so a
 * table, function, trigger, index or constraint that is created but never dropped fails
 * here instead of leaking silently into P6-D1.
 */
it('rolls back 45 to 44 and back to 45 while preserving every earlier gate', function () {
    $frontier = '2026_07_14_000028_create_cart_abandonment_and_reminders.php';
    $boundary = '2026_07_14_000029_create_affiliate_schema_foundation.php';

    $harness = new PhaseMigrationHarness('digitrove_p6d0_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($frontier);
        $pdo = $harness->ownerPdo();

        /** Every `affiliate%` relation PostgreSQL knows about — not a hand-written list. */
        $affiliateTables = static fn (): array => array_map('strval', $pdo->query(<<<'SQL'
            SELECT table_name FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name LIKE 'affiliate%'
            ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN));

        $affiliateFunctions = static fn (): array => array_map('strval', $pdo->query(<<<'SQL'
            SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname LIKE '%affiliate%'
            ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN));

        $affiliateTriggers = static fn (): array => array_map('strval', $pdo->query(<<<'SQL'
            SELECT tgname FROM pg_trigger
            WHERE NOT tgisinternal AND tgname LIKE '%affiliate%'
            ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN));

        $affiliateIndexes = static fn (): array => array_map('strval', $pdo->query(<<<'SQL'
            SELECT indexname FROM pg_indexes
            WHERE schemaname = 'public' AND indexname LIKE 'affiliate%'
            ORDER BY 1
            SQL)->fetchAll(PDO::FETCH_COLUMN));

        $affiliateConstraints = static fn (): int => (int) $pdo->query(<<<'SQL'
            SELECT count(*) FROM pg_constraint WHERE conname LIKE 'affiliate%'
            SQL)->fetchColumn();

        $tableExists = static fn (string $t): bool => $pdo->query("SELECT to_regclass('public.".$t."')")->fetchColumn() !== null;

        // The ONE object this gate adds outside its own block: the composite FK target on
        // `order_items`. It must appear with 000029 and vanish with its rollback.
        $orderItemsTargetIndex = static fn (): bool => (bool) $pdo->query(
            "SELECT EXISTS(SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'order_items_id_order_id_unique')"
        )->fetchColumn();

        $expectedTables = [
            'affiliate_attributions', 'affiliate_codes', 'affiliate_commission_entries',
            'affiliate_commissions', 'affiliate_payout_items', 'affiliate_payouts',
            'affiliate_program_policies', 'affiliate_touches', 'affiliates',
        ];

        // ── 1. FRONTIER 000028 ── 44 migrations, not one affiliate object anywhere.
        expect($applied)->toHaveCount(44)
            ->and($tableExists('cart_reminder_attempts'))->toBeTrue()
            ->and($affiliateTables())->toBe([])
            ->and($affiliateFunctions())->toBe([])
            ->and($affiliateTriggers())->toBe([])
            ->and($affiliateIndexes())->toBe([])
            ->and($affiliateConstraints())->toBe(0)
            ->and($orderItemsTargetIndex())->toBeFalse();

        // ── 2. APPLY 000029 ── 45 migrations, exactly the P6-D0 surface appears.
        $result = $harness->applyExactMigrations([$boundary]);
        $constraintsWhenApplied = $affiliateConstraints();

        expect($result)->toContain('2026_07_14_000029_create_affiliate_schema_foundation')
            ->and($harness->ranMigrations())->toHaveCount(45)
            ->and($affiliateTables())->toBe($expectedTables)
            // Three integrity guards, no operational authority.
            ->and($affiliateFunctions())->toBe([
                'enforce_affiliate_ledger_append_only',
                'enforce_affiliate_policy_immutability',
                'enforce_affiliate_touch_subject',
            ])
            ->and($affiliateTriggers())->toBe([
                'affiliate_commission_entries_append_only_trigger',
                'affiliate_program_policies_immutability_trigger',
                'affiliate_touches_subject_trigger',
            ])
            ->and($affiliateIndexes())->toContain('affiliate_program_policies_single_active')
            ->and($constraintsWhenApplied)->toBeGreaterThan(0)
            ->and($orderItemsTargetIndex())->toBeTrue()
            // À VALIDER (P6-D1, D-058): digitrove_affiliate_executor is CLUSTER-GLOBAL — it is
            // created by provisioning (provision-runtime-roles.sql), NOT by any migration, so it
            // is visible on this harness database even though 000030 is not applied here.
            // Rescoped from "zero affiliate roles" to an EXACT inventory: exactly one, named
            // precisely, so a second %affiliate% role would still fail. And the runtime still
            // holds nothing directly on the affiliate tables at this frontier.
            ->and(array_map('strval', $pdo->query("SELECT rolname FROM pg_roles WHERE rolname LIKE '%affiliate%' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN)))->toBe(['digitrove_affiliate_executor'])
            ->and((bool) $pdo->query("SELECT has_table_privilege('digitrove_runtime', 'public.affiliate_commissions', 'SELECT')")->fetchColumn())->toBeFalse();

        // ── 3. ROLLBACK 000029 ── every affiliate object disappears, function included.
        $output = $harness->rollbackExactMigrations([$boundary]);

        expect($output)->toContain('2026_07_14_000029_create_affiliate_schema_foundation')
            ->and($harness->ranMigrations())->toHaveCount(44)
            ->and($affiliateTables())->toBe([])
            // A surviving trigger function is the classic rollback residue.
            ->and($affiliateFunctions())->toBe([])
            ->and($affiliateTriggers())->toBe([])
            ->and($affiliateIndexes())->toBe([])
            ->and($affiliateConstraints())->toBe(0)
            // And the index this gate put on a table it does not own is gone too.
            ->and($orderItemsTargetIndex())->toBeFalse();

        // ── Every earlier gate survives untouched. ──
        expect($tableExists('cart_reminder_attempts'))->toBeTrue()
            ->and($tableExists('crm_exports'))->toBeTrue()
            ->and($tableExists('crm_segments'))->toBeTrue()
            ->and($tableExists('crm_contacts'))->toBeTrue()
            ->and($tableExists('orders'))->toBeTrue()
            ->and($tableExists('order_items'))->toBeTrue()
            ->and($tableExists('refunds'))->toBeTrue()
            ->and($tableExists('users'))->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'mark_abandoned_carts')")->fetchColumn())->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'create_crm_export')")->fetchColumn())->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'refresh_crm_contact_commerce_rollup')")->fetchColumn())->toBeTrue();

        // ── 4. RE-APPLY ── the migration is not a one-way door.
        $harness->applyExactMigrations([$boundary]);

        expect($harness->ranMigrations())->toHaveCount(45)
            ->and($affiliateTables())->toBe($expectedTables)
            ->and($affiliateFunctions())->toHaveCount(3)
            ->and($affiliateTriggers())->toHaveCount(3)
            ->and($orderItemsTargetIndex())->toBeTrue()
            // Re-applying reproduces the SAME constraint surface, not a subset.
            ->and($affiliateConstraints())->toBe($constraintsWhenApplied);
    } finally {
        $harness->drop();
    }
});
