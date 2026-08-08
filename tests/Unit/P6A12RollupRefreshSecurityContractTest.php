<?php

declare(strict_types=1);

use App\Jobs\ProcessCrmCommerceRollupRefresh;
use Tests\TestCase;

uses(TestCase::class);

function p6a12AppSource(): string
{
    $paths = [
        app_path('Jobs/ProcessCrmCommerceRollupRefresh.php'),
        app_path('Console/Commands/SweepCrmCommerceRollupRefresh.php'),
        app_path('Services/Crm/CrmCommerceRollupRefreshDispatcher.php'),
        app_path('Services/Crm/CrmCommerceRollupRefreshProcessor.php'),
        app_path('Support/CrmCommerceRollupRefreshResult.php'),
        app_path('Enums/CrmCommerceRollupRefreshStatus.php'),
    ];

    return implode("\n", array_map(static fn (string $p): string => file_get_contents($p), $paths));
}

it('keeps the job payload to an id-only contact id and an ISO currency', function () {
    $parameters = (new ReflectionClass(ProcessCrmCommerceRollupRefresh::class))->getConstructor()->getParameters();

    expect($parameters)->toHaveCount(2)
        ->and($parameters[0]->getName())->toBe('contactId')
        ->and((string) $parameters[0]->getType())->toBe('int')
        ->and($parameters[1]->getName())->toBe('currency')
        ->and((string) $parameters[1]->getType())->toBe('string');
});

it('keeps the application layer free of PII, money computation and backfill', function () {
    $source = p6a12AppSource();

    expect($source)->not->toContain('customer_email')
        ->not->toContain('visitor_id')
        // No financial computation happens in PHP — money lives only in PostgreSQL.
        ->not->toContain('gross_revenue')
        ->not->toContain('net_revenue')
        ->not->toContain('amount_minor')
        ->not->toContain('total_minor')
        ->not->toMatch('/\bSUM\s*\(/i');
    // The absence of any backfill capability is proven structurally (by reflection)
    // in the sweeper test; the word itself legitimately documents the A1.2/A1.3
    // boundary in these files, so it is not grep-forbidden here.
});

it('only invokes the two EXECUTE-only authorities and never touches commerce tables or refresh directly', function () {
    $source = p6a12AppSource();

    expect($source)->toContain('process_crm_commerce_rollup_refresh')
        ->toContain('list_due_crm_commerce_rollup_refreshes')
        // Never the enqueue authority, never the P6-A1.1 refresh authority directly.
        ->not->toContain('enqueue_crm_commerce_rollup_refresh')
        ->not->toContain('refresh_crm_contact_commerce_rollup')
        // Never a direct read/write of Commerce or the outbox table.
        ->not->toMatch('/(?:from|join|into|update)\s+(?:public\.)?(?:orders|payments|refunds|crm_commerce_rollup_refresh_outbox)\b/i');
});

it('versions the P6-A1.2 configuration fail-closed and disabled by default', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/(?m)^CRM_COMMERCE_ROLLUP_REFRESH_PROCESSING_ENABLED=false$/')
        ->toMatch('/(?m)^CRM_COMMERCE_ROLLUP_REFRESH_BATCH_SIZE=50$/');
});
