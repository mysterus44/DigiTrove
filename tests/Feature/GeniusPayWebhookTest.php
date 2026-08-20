<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\WebhookProcessingStatus;
use App\Events\OrderPaid;
use App\Events\RefundSucceeded;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| Genius Pay — webhook HTTP ingress, confirmation and refund intake
|--------------------------------------------------------------------------
|
| Exercises the real route -> controller -> service -> adapter stack on real PostgreSQL,
| under `digitrove_runtime`. The provider endpoint is faked and stray requests forbidden,
| so "the body is never authoritative" is measured, not asserted.
|
| Helpers and configuration (gp*) are shared from GeniusPayAdapterTest.
|
*/

const GENIUSPAY_WEBHOOK_URI = '/api/webhooks/payments/geniuspay';

beforeEach(function (): void {
    config(['payments' => ['driver' => 'geniuspay', 'geniuspay' => gpConfig()]]);
    Event::fake([OrderPaid::class, RefundSucceeded::class]);

    // The provider's own record, mutable per test through gpwFakeVerification().
    $this->providerStatus = 'completed';
    $this->providerAmount = 15_000;
    $this->providerCurrency = 'XOF';

    Http::preventStrayRequests();

    // ONE stub, registered once, reading the current state when the call happens.
    //
    // Http::fake() MERGES stubs rather than replacing them, and the first matching pattern
    // wins - so calling it a second time with the same URL pattern is silently a no-op. A
    // test that re-faked mid-scenario would keep getting the FIRST answer and quietly prove
    // nothing. Measured, not assumed: it is what made the refund tests fail.
    Http::fake(['pay.genius.ci/*' => fn () => Http::response([
        'data' => [
            'reference' => GENIUSPAY_REFERENCE,
            'status' => $this->providerStatus,
            'amount' => $this->providerAmount,
            'currency' => $this->providerCurrency,
            'payment_method' => 'orange_money',
        ],
    ], 200)]);
});

/** A pending order with one line, mirroring the P3-D4 fixtures. */
function gpwOrder(array $attributes = []): Order
{
    return DB::transaction(function () use ($attributes): Order {
        $purchasedProductId = Product::factory()->create()->id;
        $order = Order::factory()->create(array_merge([
            'status' => OrderStatus::Pending,
            'total_minor' => 15_000,
            'subtotal_minor' => 15_000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'currency' => 'XOF',
            'user_id' => null,
            'visitor_id' => null,
            'coupon_id' => null,
            'expires_at' => now()->addMinutes(30),
            'placed_at' => now(),
        ], $attributes));

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => null,
            'purchased_product_id' => $purchasedProductId,
            'product_name_snapshot' => 'Genius Product',
            'product_slug_snapshot' => 'genius-product',
            'product_type_snapshot' => 'ebook',
            'unit_price_minor' => $order->subtotal_minor,
            'quantity' => 1,
            'line_subtotal_minor' => $order->subtotal_minor,
            'line_discount_minor' => $order->discount_minor,
            'line_total_minor' => $order->total_minor,
            'currency' => $order->currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order;
    });
}

/**
 * A GeniusPay attempt. The reference is what P3-D3 persists at FINALISATION, so passing
 * `null` reproduces the window where the payment exists but is not yet findable by it.
 */
function gpwPayment(Order $order, ?string $reference = GENIUSPAY_REFERENCE, array $attributes = []): Payment
{
    return DB::transaction(fn (): Payment => Payment::factory()->create(array_merge([
        'public_id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'provider' => 'geniuspay',
        'provider_payment_reference' => $reference,
        'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
        'attempt_number' => 1,
        'amount_minor' => $order->total_minor,
        'currency' => $order->currency,
        'status' => PaymentStatus::Pending,
        'initiated_at' => now(),
    ], $attributes)));
}

/** What `GET /payments/{reference}` will report. Mutates state; never re-registers a stub. */
function gpwFakeVerification(string $status, int $amount = 15_000, string $currency = 'XOF'): void
{
    test()->providerStatus = $status;
    test()->providerAmount = $amount;
    test()->providerCurrency = $currency;
}

/** The exact JSON bytes of a webhook body. Encoded ONCE, then signed and posted as-is. */
function gpwBody(string $eventId, string $event = 'payment.completed', string $reference = GENIUSPAY_REFERENCE): string
{
    return (string) json_encode([
        'id' => $eventId,
        'event' => $event,
        'data' => [
            'reference' => $reference,
            'status' => $event === 'payment.refunded' ? 'refunded' : 'completed',
            'amount' => 15_000,
            'currency' => 'XOF',
        ],
    ]);
}

/**
 * POST raw bytes with a signature computed over `$bodySigned`. When `$bodyPosted` differs,
 * the request carries a body nobody signed — the tampering case.
 */
function gpwPost(string $bodySigned, ?string $bodyPosted = null, ?string $timestamp = null, bool $validSignature = true)
{
    $timestamp ??= (string) CarbonImmutable::now()->getTimestamp();

    $signature = $validSignature
        ? hash_hmac('sha256', $timestamp.'.'.$bodySigned, GENIUSPAY_WEBHOOK_SECRET)
        : str_repeat('0', 64);

    return test()->call(
        'POST',
        GENIUSPAY_WEBHOOK_URI,
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
        ],
        $bodyPosted ?? $bodySigned,
    );
}

it('answers the health ping with 200 and writes nothing', function (): void {
    $this->get(GENIUSPAY_WEBHOOK_URI)->assertOk();

    expect(PaymentWebhookEvent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Confirmation
|--------------------------------------------------------------------------
*/

it('confirms a signed webhook end to end, locating the payment by its reference', function (): void {
    gpwFakeVerification('completed');
    $order = gpwOrder();
    $payment = gpwPayment($order);

    gpwPost(gpwBody('evt_confirm_1'))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('rejects an invalid signature with 401 and records a minimal failed event', function (): void {
    $order = gpwOrder();
    gpwPayment($order);

    gpwPost(gpwBody('evt_bad_sig'), validSignature: false)->assertStatus(401);

    $event = PaymentWebhookEvent::query()->sole();
    expect($event->signature_verified)->toBeFalse()
        ->and($event->processing_status)->toBe(WebhookProcessingStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
    // No counter-call: an unsigned webhook never authorises one.
    Http::assertNothingSent();
});

it('rejects a body altered after signing — the signature covers the raw bytes', function (): void {
    $order = gpwOrder();
    gpwPayment($order);

    $signed = gpwBody('evt_tamper');
    // Same JSON semantically; different bytes. Re-encoding before verifying would ACCEPT it.
    $posted = str_replace('","event"', '" ,"event"', $signed);

    gpwPost($signed, $posted)->assertStatus(401);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    Http::assertNothingSent();
});

it('refuses a form-encoded body outright', function (): void {
    $this->post(GENIUSPAY_WEBHOOK_URI, ['id' => 'evt_form'])->assertStatus(422);

    expect(PaymentWebhookEvent::query()->count())->toBe(0);
});

it('refuses a webhook without an event id or without a reference', function (array $body): void {
    gpwPost((string) json_encode($body))->assertStatus(422);

    expect(PaymentWebhookEvent::query()->count())->toBe(0);
})->with([
    'no id' => [['event' => 'payment.completed', 'data' => ['reference' => GENIUSPAY_REFERENCE]]],
    'no reference' => [['id' => 'evt_x', 'event' => 'payment.completed', 'data' => ['status' => 'completed']]],
    'hostile reference' => [['id' => 'evt_x', 'data' => ['reference' => '../../admin']]],
]);

it('treats the provider counter-call as authoritative over the webhook body', function (): void {
    // The body says completed; the provider's own record says failed.
    gpwFakeVerification('failed');
    $order = gpwOrder();
    $payment = gpwPayment($order);

    gpwPost(gpwBody('evt_liar'))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderPaid::class);
});

it('keeps a single event and confirms once when the same event id is replayed', function (): void {
    gpwFakeVerification('completed');
    $order = gpwOrder();
    $payment = gpwPayment($order);

    gpwPost(gpwBody('evt_replay'))->assertOk();
    gpwPost(gpwBody('evt_replay'))->assertOk();

    expect(PaymentWebhookEvent::query()->where('signature_verified', true)->count())->toBe(1)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

/*
|--------------------------------------------------------------------------
| The P3-D3 window — unresolved must stay RETRYABLE, never terminal
|--------------------------------------------------------------------------
*/

it('answers 503 and keeps the event received when the reference is not yet persisted', function (): void {
    gpwFakeVerification('completed');
    $order = gpwOrder();
    // Exactly the P3-D3 window: the attempt is committed, the reference is not yet stored.
    $payment = gpwPayment($order, reference: null);

    gpwPost(gpwBody('evt_early'))->assertStatus(503);

    $event = PaymentWebhookEvent::query()->sole();
    expect($event->processing_status)->toBe(WebhookProcessingStatus::Received)
        // NOT `ignored`: ignored is terminal, and a paid order would never be confirmed.
        ->and($event->processing_status)->not->toBe(WebhookProcessingStatus::Ignored)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('confirms on the retry once the reference has landed', function (): void {
    gpwFakeVerification('completed');
    $order = gpwOrder();
    $payment = gpwPayment($order, reference: null);

    gpwPost(gpwBody('evt_early_then_ok'))->assertStatus(503);

    // P3-D3's second transaction finally persists the reference.
    DB::transaction(fn () => $payment->forceFill(['provider_payment_reference' => GENIUSPAY_REFERENCE])->save());

    gpwPost(gpwBody('evt_early_then_ok'))->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(PaymentWebhookEvent::query()->where('signature_verified', true)->count())->toBe(1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

/*
|--------------------------------------------------------------------------
| Refund intake — the missing link
|--------------------------------------------------------------------------
*/

/** Drive a real confirmation so the payment is genuinely `succeeded` and the order `paid`. */
function gpwPaidOrder(): array
{
    gpwFakeVerification('completed');
    $order = gpwOrder();
    $payment = gpwPayment($order);
    gpwPost(gpwBody('evt_paid_'.Str::random(8)))->assertOk();

    return ['order' => $order->refresh(), 'payment' => $payment->refresh()];
}

it('creates the refund, completes it through the existing chain and refunds the order', function (): void {
    ['order' => $order, 'payment' => $payment] = gpwPaidOrder();
    gpwFakeVerification('refunded');

    gpwPost(gpwBody('evt_refund_1', 'payment.refunded'))->assertOk();

    $refund = Refund::query()->sole();
    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and((int) $refund->amount_minor)->toBe((int) $payment->amount_minor)
        ->and($refund->currency)->toBe($payment->currency)
        ->and($refund->provider)->toBe('geniuspay')
        ->and($refund->provider_refund_reference)->toBe('evt_refund_1')
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);

    // The chain the intake service exists to reach, rather than bypass.
    Event::assertDispatchedTimes(RefundSucceeded::class, 1);
});

it('derives the idempotency key from the event id and never stores it raw', function (): void {
    gpwPaidOrder();
    gpwFakeVerification('refunded');

    gpwPost(gpwBody('evt_refund_key', 'payment.refunded'))->assertOk();

    $refund = Refund::query()->sole();
    expect($refund->idempotency_key_hash)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($refund->idempotency_key_hash)->toBe(hash('sha256', "geniuspay\nrefund\nevt_refund_key"))
        ->and($refund->idempotency_key_hash)->not->toContain('evt_refund_key');
});

it('creates exactly one refund when the same refund event is replayed', function (): void {
    ['order' => $order] = gpwPaidOrder();
    gpwFakeVerification('refunded');

    gpwPost(gpwBody('evt_refund_replay', 'payment.refunded'))->assertOk();
    gpwPost(gpwBody('evt_refund_replay', 'payment.refunded'))->assertOk();

    expect(Refund::query()->count())->toBe(1)
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

it('creates no second refund when a DIFFERENT event id repeats an already total refund', function (): void {
    gpwPaidOrder();
    gpwFakeVerification('refunded');

    gpwPost(gpwBody('evt_refund_a', 'payment.refunded'))->assertOk();
    // A distinct id for the same refund must be an idempotent no-op, not a 500 from the
    // cumulative-cap trigger and certainly not a second refund.
    gpwPost(gpwBody('evt_refund_b', 'payment.refunded'))->assertOk();

    expect(Refund::query()->count())->toBe(1);
});

it('records no refund when the provider does not itself report the payment as refunded', function (): void {
    ['order' => $order] = gpwPaidOrder();
    // The body claims a refund; the provider's own record still says completed.
    gpwFakeVerification('completed');

    gpwPost(gpwBody('evt_refund_liar', 'payment.refunded'))->assertOk();

    expect(Refund::query()->count())->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
    Event::assertNotDispatched(RefundSucceeded::class);
});

it('answers 503 for a refund whose payment is not succeeded, leaving it retryable', function (): void {
    gpwFakeVerification('refunded');
    $order = gpwOrder();
    // A refund notification that overtook the confirmation. The schema forbids a refund
    // against a non-succeeded payment, so this must stay retryable rather than be lost.
    gpwPayment($order);

    gpwPost(gpwBody('evt_refund_early', 'payment.refunded'))->assertStatus(503);

    expect(Refund::query()->count())->toBe(0)
        ->and(PaymentWebhookEvent::query()->sole()->processing_status)->toBe(WebhookProcessingStatus::Received);
});

it('answers 503 for a refund naming a reference no payment carries', function (): void {
    gpwFakeVerification('refunded');

    gpwPost(gpwBody('evt_refund_ghost', 'payment.refunded'))->assertStatus(503);

    expect(Refund::query()->count())->toBe(0);
});
