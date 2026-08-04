<?php

declare(strict_types=1);

use App\Enums\CrmOrderAttributionReason;
use App\Enums\CrmOrderAttributionSource;
use App\Enums\CrmOrderAttributionStatus;
use App\Enums\OrderStatus;
use App\Jobs\ProcessCrmOrderAttribution;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Crm\CrmOperationException;
use App\Services\Crm\CrmOrderAttributionProcessor;
use App\Support\CrmOrderAttributionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.order_attribution.processing_enabled' => true,
        'crm.order_attribution.batch_size' => 50,
    ]);
});

function p6a10ProcessorAcquire(Order $order): void
{
    DB::transaction(function () use ($order): void {
        Payment::factory()->forOrder($order)->succeeded()->create();
        $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    });
}

it('processes one durable order through the runtime authority and returns a minimal DTO', function () {
    $order = $this->crmPendingOrder('processor@example.test');
    p6a10ProcessorAcquire($order);

    $result = app(CrmOrderAttributionProcessor::class)->process($order->id);

    expect($result)->toBeInstanceOf(CrmOrderAttributionResult::class)
        ->and($result->orderId)->toBe($order->id)
        ->and($result->status)->toBe(CrmOrderAttributionStatus::Attributed)
        ->and($result->reason)->toBeNull()
        ->and($result->source)->toBe(CrmOrderAttributionSource::GuestOrderResolution)
        ->and($result->contactPublicId)->toBeString()
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('processor@example.test');
});

it('lets the queue job invoke only the processor and remain replay-safe', function () {
    $order = $this->crmPendingOrder('job-processor@example.test');
    p6a10ProcessorAcquire($order);
    $job = new ProcessCrmOrderAttribution($order->id);

    $job->handle(app(CrmOrderAttributionProcessor::class));
    $job->handle(app(CrmOrderAttributionProcessor::class));

    expect(DB::connection('pgsql_migration')->table('crm_order_attributions')->where('order_id', $order->id)->count())
        ->toBe(1)
        ->and(DB::connection('pgsql_migration')->table('crm_order_attribution_outbox')->where('order_id', $order->id)->value('attempt_count'))
        ->toBe(1);
});

it('maps terminal invalid legacy email without leaking the address', function () {
    $legacyEmail = str_repeat('x', 250).'@legacy.test';
    $order = $this->crmPendingOrder($legacyEmail);
    p6a10ProcessorAcquire($order);

    $result = app(CrmOrderAttributionProcessor::class)->process($order->id);

    expect($result->status)->toBe(CrmOrderAttributionStatus::Unattributable)
        ->and($result->reason)->toBe(CrmOrderAttributionReason::InvalidEmailContract)
        ->and($result->contactPublicId)->toBeNull()
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain($legacyEmail);
});

it('refuses disabled processing ambient transactions and the migration identity with one sanitized error', function () {
    $order = $this->crmPendingOrder('boundary@example.test');
    p6a10ProcessorAcquire($order);
    $processor = app(CrmOrderAttributionProcessor::class);

    config(['crm.order_attribution.processing_enabled' => false]);
    expect(fn () => $processor->process($order->id))
        ->toThrow(CrmOperationException::class, 'The CRM operation could not be completed.');

    config(['crm.order_attribution.processing_enabled' => true]);
    DB::beginTransaction();
    try {
        expect(fn () => $processor->process($order->id))
            ->toThrow(CrmOperationException::class, 'The CRM operation could not be completed.');
    } finally {
        DB::rollBack();
    }

    $default = config('database.default');
    config(['database.default' => 'pgsql_migration']);
    DB::purge('pgsql_migration');
    try {
        expect(fn () => $processor->process($order->id))
            ->toThrow(CrmOperationException::class, 'The CRM operation could not be completed.');
    } finally {
        config(['database.default' => $default]);
        DB::purge('pgsql_migration');
    }
});

it('does not log CRM identity evidence while processing', function () {
    Log::spy();
    $order = $this->crmPendingOrder('quiet-processor@example.test');
    p6a10ProcessorAcquire($order);

    app(CrmOrderAttributionProcessor::class)->process($order->id);

    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});
