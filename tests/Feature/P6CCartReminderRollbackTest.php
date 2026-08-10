<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

/**
 * P6-C rollback must restore the EXACT `000027` boundary, then re-apply cleanly.
 *
 * The inventory is read from `pg_catalog` and asserted as an exact list, so a function,
 * trigger, column or grant that is created but never dropped fails the test rather than
 * silently surviving into the next gate.
 */
it('rolls back 44 to 43 and back to 44 while preserving every earlier gate', function () {
    $frontier = '2026_07_14_000027_create_crm_exports_table.php';
    $boundary = '2026_07_14_000028_create_cart_abandonment_and_reminders.php';

    $harness = new PhaseMigrationHarness('digitrove_p6c_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($frontier);
        $pdo = $harness->ownerPdo();

        $p6cFunctions = static function () use ($pdo): array {
            return array_map('strval', $pdo->query(<<<'SQL'
                SELECT p.proname
                FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = 'public'
                  AND p.proname IN (
                    'mark_abandoned_carts', 'list_cart_reminder_candidates', 'enqueue_cart_reminder',
                    'list_due_cart_reminders', 'claim_cart_reminder', 'attach_cart_reminder_secret',
                    'complete_cart_reminder', 'suppress_cart_reminder', 'fail_cart_reminder',
                    'resolve_cart_reminder_by_secret', 'purge_cart_reminders', 'touch_cart_last_activity'
                  )
                ORDER BY 1
                SQL)->fetchAll(PDO::FETCH_COLUMN));
        };

        $tableExists = static fn (string $t): bool => $pdo->query("SELECT to_regclass('public.".$t."')")->fetchColumn() !== null;
        $columnExists = static fn (string $t, string $c): bool => (bool) $pdo->query(
            "SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_name = '{$t}' AND column_name = '{$c}')"
        )->fetchColumn();
        $triggerExists = static fn (string $name): bool => (bool) $pdo->query(
            "SELECT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname = '{$name}' AND NOT tgisinternal)"
        )->fetchColumn();
        $indexExists = static fn (string $name): bool => (bool) $pdo->query(
            "SELECT EXISTS(SELECT 1 FROM pg_indexes WHERE indexname = '{$name}')"
        )->fetchColumn();
        $tablePriv = static fn (string $role, string $table, string $priv): bool => (bool) $pdo->query(
            "SELECT has_table_privilege('{$role}', 'public.{$table}', '{$priv}')"
        )->fetchColumn();

        // ── 1. FRONTIER 000027 ── 43 migrations, no P6-C object anywhere.
        expect($applied)->toHaveCount(43)
            ->and($tableExists('crm_exports'))->toBeTrue()
            ->and($tableExists('cart_reminder_attempts'))->toBeFalse()
            ->and($columnExists('carts', 'last_activity_at'))->toBeFalse()
            ->and($triggerExists('cart_items_touch_cart_activity_trigger'))->toBeFalse()
            ->and($p6cFunctions())->toBe([])
            // The executor has NO Commerce privilege before P6-C grants it.
            ->and($tablePriv('digitrove_crm_executor', 'carts', 'SELECT'))->toBeFalse();

        // ── 2. APPLY 000028 ── 44 migrations, exactly the P6-C surface appears.
        $result = $harness->applyExactMigrations([$boundary]);
        expect($result)->toContain('2026_07_14_000028_create_cart_abandonment_and_reminders')
            ->and($harness->ranMigrations())->toHaveCount(44)
            ->and($tableExists('cart_reminder_attempts'))->toBeTrue()
            ->and($columnExists('carts', 'last_activity_at'))->toBeTrue()
            ->and($triggerExists('cart_items_touch_cart_activity_trigger'))->toBeTrue()
            ->and($indexExists('carts_active_last_activity_index'))->toBeTrue()
            ->and($p6cFunctions())->toHaveCount(12)
            ->and($tablePriv('digitrove_crm_executor', 'carts', 'SELECT'))->toBeTrue()
            ->and($tablePriv('digitrove_crm_executor', 'carts', 'UPDATE'))->toBeTrue()
            ->and($tablePriv('digitrove_crm_executor', 'orders', 'SELECT'))->toBeTrue()
            // The runtime never gets the table itself.
            ->and($tablePriv('digitrove_runtime', 'cart_reminder_attempts', 'SELECT'))->toBeFalse();

        // ── 3. ROLLBACK 000028 ── every P6-C object disappears, grants included.
        $output = $harness->rollbackExactMigrations([$boundary]);
        expect($output)->toContain('2026_07_14_000028_create_cart_abandonment_and_reminders')
            ->and($harness->ranMigrations())->toHaveCount(43)
            ->and($tableExists('cart_reminder_attempts'))->toBeFalse()
            ->and($columnExists('carts', 'last_activity_at'))->toBeFalse()
            ->and($triggerExists('cart_items_touch_cart_activity_trigger'))->toBeFalse()
            ->and($indexExists('carts_active_last_activity_index'))->toBeFalse()
            ->and($p6cFunctions())->toBe([])
            // The Commerce privilege boundary is restored exactly.
            ->and($tablePriv('digitrove_crm_executor', 'carts', 'SELECT'))->toBeFalse()
            ->and($tablePriv('digitrove_crm_executor', 'carts', 'UPDATE'))->toBeFalse()
            ->and($tablePriv('digitrove_crm_executor', 'orders', 'SELECT'))->toBeFalse()
            ->and($tablePriv('digitrove_crm_executor', 'users', 'SELECT'))->toBeFalse();

        // ── Every earlier gate survives untouched. ──
        expect($tableExists('crm_exports'))->toBeTrue()
            ->and($tableExists('crm_segments'))->toBeTrue()
            ->and($tableExists('crm_contacts'))->toBeTrue()
            ->and($tableExists('carts'))->toBeTrue()
            ->and($tableExists('orders'))->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'create_crm_export')")->fetchColumn())->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'list_crm_contacts')")->fetchColumn())->toBeTrue()
            ->and((bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'validate_crm_segment_definition_v1')")->fetchColumn())->toBeTrue();

        // ── 4. RE-APPLY ── the migration is not a one-way door.
        $harness->applyExactMigrations([$boundary]);
        expect($harness->ranMigrations())->toHaveCount(44)
            ->and($tableExists('cart_reminder_attempts'))->toBeTrue()
            ->and($columnExists('carts', 'last_activity_at'))->toBeTrue()
            ->and($p6cFunctions())->toHaveCount(12);
    } finally {
        $harness->drop();
    }
});
