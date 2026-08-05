<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

it('rolls back only P6-A1.1 while preserving P6-A1.0 and earlier phases', function () {
    $boundary = '2026_07_14_000022_create_crm_contact_commerce_rollups.php';
    $harness = new PhaseMigrationHarness('digitrove_p6a11_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough($boundary);
        $ownerPdo = $harness->ownerPdo();

        // 1. Initial State assertions
        expect((bool) $ownerPdo->query("SELECT EXISTS(SELECT 1 FROM pg_tables WHERE tablename = 'crm_contact_commerce_rollups')")->fetchColumn())->toBeTrue()
            ->and((bool) $ownerPdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'refresh_crm_contact_commerce_rollup')")->fetchColumn())->toBeTrue();

        // 2. Perform Rollback of the single migration 000022
        $output = $harness->rollbackExactMigrations([$boundary]);
        expect($output)->toContain('2026_07_14_000022_create_crm_contact_commerce_rollups');

        // 3. Post-rollback assertions (P6-A1.1)
        expect((bool) $ownerPdo->query("SELECT EXISTS(SELECT 1 FROM pg_tables WHERE tablename = 'crm_contact_commerce_rollups')")->fetchColumn())->toBeFalse()
            ->and((bool) $ownerPdo->query("SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = 'refresh_crm_contact_commerce_rollup')")->fetchColumn())->toBeFalse();

        // 4. Preserved state assertions (P6-A1.0 and P6-A0 and roles)
        expect((bool) $ownerPdo->query("SELECT EXISTS(SELECT 1 FROM pg_tables WHERE tablename = 'crm_order_attributions')")->fetchColumn())->toBeTrue()
            ->and((bool) $ownerPdo->query("SELECT EXISTS(SELECT 1 FROM pg_tables WHERE tablename = 'crm_order_attribution_outbox')")->fetchColumn())->toBeTrue()
            ->and((bool) $ownerPdo->query("SELECT EXISTS(SELECT 1 FROM pg_tables WHERE tablename = 'crm_contacts')")->fetchColumn())->toBeTrue()
            ->and((bool) $ownerPdo->query("SELECT EXISTS(SELECT 1 FROM pg_roles WHERE rolname = 'digitrove_crm_executor')")->fetchColumn())->toBeTrue();

    } finally {
        $harness->drop();
    }
});
