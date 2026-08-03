<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Jobs\ProcessCrmOrderAttribution;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Crm\CrmOrderAttributionProcessor;
use App\Support\CrmConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.order_attribution.processing_enabled' => true,
        'crm.order_attribution.batch_size' => 50,
        'cache.default' => 'array',
    ]);
});

function p6a10SweeperAcquire(Order $order): void
{
    DB::transaction(function () use ($order): void {
        Payment::factory()->forOrder($order)->succeeded()->create();
        $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });
}

it('fails closed when disabled and emits only a counter', function () {
    config(['crm.order_attribution.processing_enabled' => false]);
    Bus::fake();

    expect(Artisan::call('crm:dispatch-order-attributions'))->toBe(Command::SUCCESS)
        ->and(trim(Artisan::output()))->toBe('dispatched=0');
    Bus::assertNothingDispatched();
});

it('recovers a lost event signal by dispatching each due order id once', function () {
    $first = $this->crmPendingOrder('sweeper-one@example.test');
    $second = $this->crmPendingOrder('sweeper-two@example.test');
    p6a10SweeperAcquire($first);
    p6a10SweeperAcquire($second);
    Bus::fake();

    expect(CrmConfig::orderAttributionProcessingEnabled())->toBeTrue();

    expect(Artisan::call('crm:dispatch-order-attributions'))->toBe(Command::SUCCESS)
        ->and(trim(Artisan::output()))->toBe('dispatched=2');
    Bus::assertDispatchedTimes(ProcessCrmOrderAttribution::class, 2);
    Bus::assertDispatched(ProcessCrmOrderAttribution::class, fn (ProcessCrmOrderAttribution $job): bool => $job->orderId === $first->id);
    Bus::assertDispatched(ProcessCrmOrderAttribution::class, fn (ProcessCrmOrderAttribution $job): bool => $job->orderId === $second->id);
});

it('respects the bounded batch and never redispatches a terminal row', function () {
    config(['crm.order_attribution.batch_size' => 1]);
    $terminal = $this->crmPendingOrder('terminal@example.test');
    $pending = $this->crmPendingOrder('pending@example.test');
    p6a10SweeperAcquire($terminal);
    p6a10SweeperAcquire($pending);
    app(CrmOrderAttributionProcessor::class)->process($terminal->id);
    Bus::fake();

    Artisan::call('crm:dispatch-order-attributions');

    Bus::assertDispatchedTimes(ProcessCrmOrderAttribution::class, 1);
    Bus::assertDispatched(ProcessCrmOrderAttribution::class, fn (ProcessCrmOrderAttribution $job): bool => $job->orderId === $pending->id);
});
