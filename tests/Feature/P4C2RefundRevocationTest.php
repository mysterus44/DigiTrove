<?php

declare(strict_types=1);

use App\Enums\GrantRevocationReason;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Delivery\GrantIssuanceService;
use App\Services\Delivery\RefundCompletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| P4-C2 — Refund grant revocation (D-035)
|--------------------------------------------------------------------------
*/

function p4cRefund(Payment $payment, int $amount): Refund
{
    return DB::transaction(fn () => Refund::factory()->create([
        'public_id' => (string) Str::uuid(),
        'payment_id' => $payment->id,
        'provider' => $payment->provider,
        'provider_refund_reference' => null,
        'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
        'amount_minor' => $amount,
        'currency' => $payment->currency,
        'status' => RefundStatus::Pending,
        'requested_at' => now(),
    ]));
}

function p4cRefundService(): RefundCompletionService
{
    return new RefundCompletionService;
}

/** A deliverable order with issued (active) grants; returns [payment, grantCount]. */
function p4cOrderWithGrants(): array
{
    p4cConfig();
    $product = p4cProduct();
    p4cFile($product, name: 'a.zip');
    p4cFile($product, name: 'b.zip');
    ['order' => $order] = p4cDeliverableOrder($product);
    (new GrantIssuanceService)->issueForOrder($order->id);

    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

    return ['order' => $order, 'payment' => $payment];
}

it('climbs the refund ladder pending -> processing -> succeeded', function (): void {
    ['payment' => $payment] = p4cOrderWithGrants();
    $refund = p4cRefund($payment, 5_000);

    p4cRefundService()->completeSucceededRefund($refund->id, 'PROV-REF-1', 'refunded');

    $refund->refresh();
    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->processing_at)->not->toBeNull()
        ->and($refund->succeeded_at)->not->toBeNull()
        ->and($refund->provider_refund_reference)->toBe('PROV-REF-1');
});

it('moves the order to partially_refunded and keeps the grants on a partial refund', function (): void {
    ['order' => $order, 'payment' => $payment] = p4cOrderWithGrants();
    $refund = p4cRefund($payment, 5_000); // < 15000

    $result = p4cRefundService()->completeSucceededRefund($refund->id, 'PROV-REF-1');

    expect($order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($result->revokedGrantCount)->toBe(0)
        ->and(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(2);
});

it('moves the order to refunded and revokes all active grants atomically on a full refund', function (): void {
    ['order' => $order, 'payment' => $payment] = p4cOrderWithGrants();
    $refund = p4cRefund($payment, 15_000); // == payment amount

    $result = p4cRefundService()->completeSucceededRefund($refund->id, 'PROV-REF-FULL');

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($result->revokedGrantCount)->toBe(2)
        ->and(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(DownloadGrant::query()->where('revoked_reason_code', GrantRevocationReason::FullRefund->value)->count())->toBe(2)
        // G4 would reject the COMMIT if Order=refunded and an active grant survived.
        ->and(DownloadGrant::query()->distinct()->pluck('revoked_at'))->toHaveCount(1);
});

it('is idempotent on replay with the same reference', function (): void {
    ['order' => $order, 'payment' => $payment] = p4cOrderWithGrants();
    $refund = p4cRefund($payment, 15_000);

    p4cRefundService()->completeSucceededRefund($refund->id, 'PROV-REF-FULL');
    $beforeReplay = DownloadGrant::query()
        ->orderBy('id')
        ->get(['id', 'revoked_at', 'revoked_reason_code'])
        ->map(fn (DownloadGrant $grant): array => [
            'id' => $grant->id,
            'revoked_at' => $grant->revoked_at?->toISOString(),
            'reason' => $grant->revoked_reason_code,
        ])
        ->all();
    $result = p4cRefundService()->completeSucceededRefund($refund->id, 'PROV-REF-FULL');
    $afterReplay = DownloadGrant::query()
        ->orderBy('id')
        ->get(['id', 'revoked_at', 'revoked_reason_code'])
        ->map(fn (DownloadGrant $grant): array => [
            'id' => $grant->id,
            'revoked_at' => $grant->revoked_at?->toISOString(),
            'reason' => $grant->revoked_reason_code,
        ])
        ->all();

    expect($result->isReplay)->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(Refund::query()->where('status', RefundStatus::Succeeded->value)->count())->toBe(1)
        ->and(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and($afterReplay)->toBe($beforeReplay);
});

it('refuses a different reference without overwriting the stored one', function (): void {
    ['payment' => $payment] = p4cOrderWithGrants();
    $refund = p4cRefund($payment, 5_000);

    p4cRefundService()->completeSucceededRefund($refund->id, 'ORIGINAL-REF');

    expect(fn () => p4cRefundService()->completeSucceededRefund($refund->id, 'DIFFERENT-REF'))
        ->toThrow(RuntimeException::class);
    expect($refund->refresh()->provider_refund_reference)->toBe('ORIGINAL-REF');
});

it('never deletes a grant, only revokes it', function (): void {
    ['payment' => $payment] = p4cOrderWithGrants();
    $refund = p4cRefund($payment, 15_000);

    p4cRefundService()->completeSucceededRefund($refund->id, 'PROV-REF-FULL');

    // Both grants still exist as rows, just revoked.
    expect(DownloadGrant::query()->count())->toBe(2)
        ->and(DownloadGrant::query()->whereNotNull('revoked_at')->count())->toBe(2);
});

it('rolls back the refund, order and grants when the cumulative cap is exceeded', function (): void {
    ['order' => $order, 'payment' => $payment] = p4cOrderWithGrants();
    $first = p4cRefund($payment, 10_000);
    p4cRefundService()->completeSucceededRefund($first->id, 'PROV-REF-1');

    $second = p4cRefund($payment, 6_000);
    $grantState = DownloadGrant::query()->orderBy('id')->pluck('revoked_at', 'id')->all();

    $failure = null;
    try {
        p4cRefundService()->completeSucceededRefund($second->id, 'PROV-REF-2');
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->not->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and($second->refresh()->status)->toBe(RefundStatus::Pending)
        ->and($second->provider_refund_reference)->toBeNull()
        ->and($second->processing_at)->toBeNull()
        ->and($second->succeeded_at)->toBeNull()
        ->and(DownloadGrant::query()->orderBy('id')->pluck('revoked_at', 'id')->all())->toBe($grantState)
        ->and(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(2);
});
