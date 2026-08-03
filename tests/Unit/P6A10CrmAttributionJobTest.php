<?php

declare(strict_types=1);

use App\Events\OrderPaid;
use App\Jobs\ProcessCrmOrderAttribution;
use App\Support\CrmConfig;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.order_attribution.processing_enabled' => true,
        'crm.order_attribution.batch_size' => 50,
        'cache.default' => 'array',
    ]);
});

it('queues one unique after-commit order-id-only CRM job from the weak signal', function () {
    Bus::fake();

    event(new OrderPaid(42));

    Bus::assertDispatchedTimes(ProcessCrmOrderAttribution::class, 1);
    Bus::assertDispatched(ProcessCrmOrderAttribution::class, fn (ProcessCrmOrderAttribution $job): bool => $job->orderId === 42);

    $job = new ProcessCrmOrderAttribution(42);
    $payload = serialize($job);
    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->afterCommit)->toBeTrue()
        ->and($job->queue)->toBe('crm')
        ->and($payload)->not->toContain('@')
        ->and(mb_strtolower($payload))->not->toContain('email')
        ->and(mb_strtolower($payload))->not->toContain('contact');
});

it('does not queue the weak signal when processing is disabled', function () {
    config(['crm.order_attribution.processing_enabled' => false]);
    Bus::fake();

    event(new OrderPaid(42));

    Bus::assertNothingDispatched();
});

it('does not queue the weak signal for an incoherent fail-closed configuration', function (array $override) {
    config($override);
    Bus::fake();

    event(new OrderPaid(42));

    Bus::assertNothingDispatched();
})->with([
    'foundation disabled' => [['crm.foundation_enabled' => false]],
    'invalid processing flag' => [['crm.order_attribution.processing_enabled' => 'yes']],
]);

it('validates processing configuration strictly', function (mixed $value) {
    config(['crm.order_attribution.batch_size' => $value]);

    expect(fn () => CrmConfig::orderAttributionBatchSize())->toThrow(RuntimeException::class);
})->with([0, 101, '1.5', ' 50', true, null]);

it('registers a fail-closed non-overlapping CRM schedule', function () {
    $routes = file_get_contents(base_path('routes/console.php'));

    expect($routes)->toContain('CrmConfig::orderAttributionProcessingEnabled()')
        ->and($routes)->toContain("Schedule::command('crm:dispatch-order-attributions')")
        ->and($routes)->toContain('withoutOverlapping()')
        ->and(collect(Schedule::events())->contains(
            fn ($event): bool => str_contains((string) $event->command, 'crm:dispatch-order-attributions'),
        ))->toBeFalse();
});
