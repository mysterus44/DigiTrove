<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

function p6a13Sources(): string
{
    $paths = [
        app_path('Console/Commands/BackfillCrmCommerceRollups.php'),
        app_path('Services/Crm/CrmCommerceRollupBackfillService.php'),
    ];

    return implode("\n", array_map(static fn (string $p): string => file_get_contents($p), $paths));
}

function p6a13Migration(): string
{
    return file_get_contents(
        base_path('database/migrations/2026_07_14_000024_create_crm_commerce_rollup_backfill_runs.php'),
    );
}

it('adds no backfill job, listener or scheduler entry', function () {
    // The only asynchronous pipeline stays P6-A1.2. A backfill is operator-driven.
    expect(glob(app_path('Jobs/*Backfill*.php')) ?: [])->toBe([])
        ->and(glob(app_path('Listeners/*Backfill*.php')) ?: [])->toBe([])
        ->and(glob(app_path('Jobs/*backfill*.php')) ?: [])->toBe([]);

    $routes = file_get_contents(base_path('routes/console.php'));
    expect($routes)->not->toContain('crm:backfill-commerce-rollups')
        ->and(mb_strtolower($routes))->not->toContain('backfill');

    expect(p6a13Sources())->not->toContain('ShouldQueue')
        ->not->toContain('ShouldBeUnique')
        ->not->toContain('dispatch(');
});

it('never resolves identity and never computes money in the application layer', function () {
    $source = p6a13Sources();

    expect($source)->not->toContain('customer_email')
        ->not->toContain('visitor_id')
        ->not->toContain('resolve_crm_contact')
        ->not->toContain('gross_revenue')
        ->not->toContain('net_revenue')
        ->not->toContain('amount_minor')
        ->not->toContain('total_minor')
        ->not->toMatch('/\bSUM\s*\(/i');
});

it('drives only the backfill authorities and never enqueue or refresh directly', function () {
    $source = p6a13Sources();

    expect($source)->toContain('start_crm_commerce_rollup_backfill')
        ->toContain('process_crm_commerce_rollup_backfill_batch')
        ->toContain('list_crm_commerce_rollup_backfill_candidates')
        // The P6-A1.2 and P6-A1.1 authorities are never called from PHP.
        ->not->toContain('enqueue_crm_commerce_rollup_refresh')
        ->not->toContain('refresh_crm_contact_commerce_rollup')
        // No direct read or write of commerce, the outbox or the rollup projection.
        ->not->toMatch('/(?:from|join|into|update)\s+(?:public\.)?(?:orders|payments|refunds|crm_contact_commerce_rollups|crm_commerce_rollup_refresh_outbox|crm_commerce_rollup_backfill_runs)\b/i');
});

it('keeps the migration free of identity resolution, money and trigger bypass', function () {
    $migration = p6a13Migration();

    expect($migration)->not->toContain('customer_email')
        ->not->toContain('resolve_crm_contact')
        ->not->toContain('session_replication_role')
        ->not->toContain('DISABLE TRIGGER')
        ->not->toContain('variable_conflict')
        ->not->toContain('CREATE ROLE')
        // It reads commerce only through the authoritative attribution join.
        ->toContain('crm_order_attributions')
        ->toContain('enqueue_crm_commerce_rollup_refresh')
        // and never touches the money authority itself.
        ->not->toContain('refresh_crm_contact_commerce_rollup(p_');
});

it('uses keyset pagination and never OFFSET', function () {
    $migration = p6a13Migration();

    expect($migration)->toContain('ROW(coa.contact_id, o.currency)')
        ->toContain('ORDER BY 1, 2')
        ->and(mb_strtolower($migration))->not->toContain('offset');
});

it('versions the backfill configuration fail-closed and disabled by default', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/(?m)^CRM_COMMERCE_ROLLUP_BACKFILL_ENABLED=false$/');
    expect(config('crm.commerce_rollup_backfill.enabled'))->toBeFalse();
});
