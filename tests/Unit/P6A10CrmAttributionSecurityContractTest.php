<?php

declare(strict_types=1);

use App\Events\OrderPaid;
use App\Jobs\ProcessCrmOrderAttribution;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the event and job payload to one integer order id', function () {
    foreach ([OrderPaid::class, ProcessCrmOrderAttribution::class] as $class) {
        $parameters = (new ReflectionClass($class))->getConstructor()->getParameters();
        expect($parameters)->toHaveCount(1)
            ->and($parameters[0]->getName())->toBe('orderId')
            ->and((string) $parameters[0]->getType())->toBe('int');
    }
});

it('keeps the application pipeline free of CRM PII direct tables and future scope', function () {
    $paths = [
        app_path('Jobs/ProcessCrmOrderAttribution.php'),
        app_path('Listeners/QueueCrmOrderAttribution.php'),
        app_path('Services/Crm/CrmOrderAttributionProcessor.php'),
        app_path('Services/Crm/CrmOrderAttributionDispatcher.php'),
        app_path('Console/Commands/DispatchCrmOrderAttributions.php'),
    ];
    $source = implode("\n", array_map(static fn (string $path): string => file_get_contents($path), $paths));

    expect($source)->not->toContain('customer_email')
        ->not->toContain('visitor_id')
        ->not->toMatch('/(?:from|join|update|into|delete\s+from)\s+(?:public\.)?crm_order_attribution(?:_outbox|s)\b/i')
        ->not->toMatch('/DB::table\([^)]*crm_order_attribution/i')
        ->not->toMatch('/rollup|lifetime_value|segment|campaign|affiliate|backfill/i');
});

it('versions fail-closed non-sensitive attribution configuration only', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/(?m)^CRM_ORDER_ATTRIBUTION_PROCESSING_ENABLED=false$/')
        ->toMatch('/(?m)^CRM_ORDER_ATTRIBUTION_BATCH_SIZE=50$/');
});
