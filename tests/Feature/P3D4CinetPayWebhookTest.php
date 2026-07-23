<?php

declare(strict_types=1);

use App\Contracts\Payments\ProviderWebhookEnvelope;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Events\OrderPaid;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| P3-D4 — CinetPay webhook HTTP ingress (D-034)
|--------------------------------------------------------------------------
|
| Exercises the real route → controller → service → adapter stack on real
| PostgreSQL. The provider /check endpoint is faked; stray requests are
| forbidden. Helpers (p3d4*) are shared from P3D4PaymentConfirmationTest.
|
*/

const CINETPAY_WEBHOOK_URI = '/api/webhooks/payments/cinetpay';

beforeEach(function (): void {
    // The container-resolved provider must use the test secret/URLs.
    config(['payments' => p3d4Config()]);
    Event::fake([OrderPaid::class]);
});

/** POST a webhook envelope as form data with its x-token header. */
function postCinetPayWebhook(ProviderWebhookEnvelope $envelope)
{
    return test()->post(
        CINETPAY_WEBHOOK_URI,
        $envelope->params,
        ['x-token' => (string) $envelope->header('x-token')],
    );
}

it('answers the health ping with 200 and writes nothing', function (): void {
    $this->get(CINETPAY_WEBHOOK_URI)->assertOk();

    expect(PaymentWebhookEvent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('processes a valid signed webhook end to end and returns 200', function (): void {
    p3d4FakeCheck('ACCEPTED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    postCinetPayWebhook(p3d4Webhook($payment))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('rejects an invalid signature with 401 and records a minimal failed event', function (): void {
    Http::preventStrayRequests();
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    postCinetPayWebhook(p3d4Webhook($payment, [], validSignature: false))->assertStatus(401);

    $event = PaymentWebhookEvent::query()->firstOrFail();
    expect($event->signature_verified)->toBeFalse()
        ->and($event->processing_status)->toBe(WebhookProcessingStatus::Failed)
        ->and($event->payment_id)->toBeNull()
        ->and($event->filtered_payload)->toBeNull();
    // The counter-call must never run for an invalid signature.
    Http::assertNothingSent();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('returns 422 when the required transaction id is missing', function (): void {
    Http::preventStrayRequests();

    $this->post(CINETPAY_WEBHOOK_URI, ['cpm_amount' => '15000'], ['x-token' => 'x'])
        ->assertStatus(422);

    expect(PaymentWebhookEvent::query()->count())->toBe(0);
});

it('returns 503 when the provider counter-call is unavailable', function (): void {
    Http::preventStrayRequests();
    Http::fake(fn () => throw new ConnectionException('down'));
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    postCinetPayWebhook(p3d4Webhook($payment))->assertStatus(503);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('does not reveal a missing payment: unknown transaction still returns 200', function (): void {
    p3d4FakeCheck('ACCEPTED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    postCinetPayWebhook(p3d4Webhook($payment, ['cpm_trans_id' => (string) Str::uuid()]))->assertOk();
});

// ── C1: exact signed replay is fully idempotent ──────────────────────────

it('C1 — an exact signed webhook replayed keeps a single event, confirmation and dispatch', function (): void {
    p3d4FakeCheck('ACCEPTED', 12_000);
    $coupon = DB::transaction(fn () => Coupon::factory()->create([
        'discount_type' => 'fixed', 'percent_basis_points' => null,
        'max_redemptions' => 10, 'redemptions_count' => 0,
    ]));
    $order = p3d4CouponOrder($coupon);
    $payment = p3d4Payment($order);
    $envelope = p3d4Webhook($payment);

    postCinetPayWebhook($envelope)->assertOk();
    postCinetPayWebhook($envelope)->assertOk(); // exact replay

    expect(PaymentWebhookEvent::query()->where('provider', 'cinetpay')->count())->toBe(1)
        ->and(Payment::query()->where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1)
        ->and(CouponRedemption::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($coupon->refresh()->redemptions_count)->toBe(1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('records two distinct events but confirms once for two different notifications (C2 shape)', function (): void {
    p3d4FakeCheck('ACCEPTED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    postCinetPayWebhook(p3d4Webhook($payment))->assertOk();
    postCinetPayWebhook(p3d4Webhook($payment, ['cpm_trans_date' => '2026-07-23 12:00:00']))->assertOk();

    expect(PaymentWebhookEvent::query()->count())->toBe(2)
        ->and(Payment::query()->where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});
