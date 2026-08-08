<?php

declare(strict_types=1);

use App\Jobs\ProcessCrmCommerceRollupRefresh;
use App\Services\Crm\CrmCommerceRollupRefreshDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\RollupRefreshFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.commerce_rollup_refresh.processing_enabled' => true,
        'crm.commerce_rollup_refresh.batch_size' => 50,
        'cache.default' => 'array',
    ]);
});

it('fails closed when disabled and emits only a counter', function () {
    config(['crm.commerce_rollup_refresh.processing_enabled' => false]);
    Bus::fake();

    expect(Artisan::call('crm:sweep-commerce-rollup-refresh'))->toBe(Command::SUCCESS)
        ->and(trim(Artisan::output()))->toBe('dispatched=0');
    Bus::assertNothingDispatched();
});

it('recovers due work by dispatching one unique job per contact/currency', function () {
    $c1 = Fx::contact();
    $c2 = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $c1);
    Fx::attribute(Fx::paidOrder(4000, 'USD')['order'], $c2);
    Bus::fake();

    expect(Artisan::call('crm:sweep-commerce-rollup-refresh'))->toBe(Command::SUCCESS)
        ->and(trim(Artisan::output()))->toBe('dispatched=2');

    Bus::assertDispatchedTimes(ProcessCrmCommerceRollupRefresh::class, 2);
    Bus::assertDispatched(ProcessCrmCommerceRollupRefresh::class, fn ($job): bool => $job->contactId === $c1 && $job->currency === 'XOF');
    Bus::assertDispatched(ProcessCrmCommerceRollupRefresh::class, fn ($job): bool => $job->contactId === $c2 && $job->currency === 'USD');
});

it('respects the bounded batch size', function () {
    config(['crm.commerce_rollup_refresh.batch_size' => 1]);
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], Fx::contact());
    Fx::attribute(Fx::paidOrder(4000, 'XOF')['order'], Fx::contact());
    Bus::fake();

    Artisan::call('crm:sweep-commerce-rollup-refresh');

    Bus::assertDispatchedTimes(ProcessCrmCommerceRollupRefresh::class, 1);
});

it('never redispatches an already-processed or terminal row', function () {
    $processed = Fx::contact();
    $terminal = Fx::contact();
    $due = Fx::contact();
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $processed);
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $terminal);
    Fx::attribute(Fx::paidOrder(5000, 'XOF')['order'], $due);

    Fx::process($processed, 'XOF');       // now processed == requested → not due
    Fx::markTerminal($terminal, 'XOF');    // terminal → never dispatched
    Bus::fake();

    Artisan::call('crm:sweep-commerce-rollup-refresh');

    Bus::assertDispatchedTimes(ProcessCrmCommerceRollupRefresh::class, 1);
    Bus::assertDispatched(ProcessCrmCommerceRollupRefresh::class, fn ($job): bool => $job->contactId === $due);
});

it('exposes no historical backfill capability on the dispatcher', function () {
    $methods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(CrmCommerceRollupRefreshDispatcher::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    // The sweeper only re-drives the durable outbox; scanning all orders/refunds
    // to reconstruct missing rollups is P6-A1.3, and must not exist here.
    expect($methods)->toContain('dispatchDue')
        ->and($methods)->not->toContain('backfill')
        ->and($methods)->not->toContain('backfillAll')
        ->and($methods)->not->toContain('reconcileHistory');
});
