<?php

declare(strict_types=1);

use App\Contracts\Payments\ProviderWebhookEnvelope;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Events\OrderPaid;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Payments\PaymentProviderFactory;
use App\Services\Payments\PaymentConfirmationException;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use App\Services\Payments\PaymentConfirmationService;
use App\Services\Payments\WebhookOutcome;
use App\Services\Payments\WebhookRecordingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| P3-D4 — Server-side payment confirmation (D-034)
|--------------------------------------------------------------------------
|
| Real PostgreSQL under digitrove_runtime, non-transactional harness (the
| service refuses an ambient transaction: the counter-call must run at level 0).
| The real CinetPay adapter drives every case; the check endpoint is faked and
| stray requests are forbidden, so the webhook body is proven non-authoritative.
|
*/

const P3D4_SECRET = 'unit-test-cinetpay-secret';
const P3D4_INIT_URL = 'https://api-checkout.cinetpay.com/v2/payment';
const P3D4_CHECK_URL = 'https://api-checkout.cinetpay.com/v2/payment/check';

/** Field order used for the CinetPay x-token HMAC (must match the adapter). */
const P3D4_HMAC_FIELDS = [
    'cpm_site_id', 'cpm_trans_id', 'cpm_trans_date', 'cpm_amount', 'cpm_currency',
    'signature', 'payment_method', 'cel_phone_num', 'cpm_phone_prefixe', 'cpm_language',
    'cpm_version', 'cpm_payment_config', 'cpm_page_action', 'cpm_custom', 'cpm_designation',
    'cpm_error_message',
];

function p3d4Config(): array
{
    return [
        'driver' => 'cinetpay',
        'cinetpay' => [
            'api_key' => 'test-key',
            'site_id' => '5872868',
            'secret_key' => P3D4_SECRET,
            'init_url' => P3D4_INIT_URL,
            'check_url' => P3D4_CHECK_URL,
            'channels' => 'MOBILE_MONEY',
            'lang' => 'fr',
            'connect_timeout' => 5,
            'timeout' => 15,
            'require_https' => true,
        ],
    ];
}

function p3d4Service(): PaymentConfirmationService
{
    return new PaymentConfirmationService(
        new PaymentProviderFactory(p3d4Config()),
        new WebhookRecordingService,
    );
}

/** Fake the CinetPay /check endpoint with a normalized-status-driving response. */
function p3d4FakeCheck(string $status, int $amount, string $currency = 'XOF', ?string $operatorId = 'op_ref_1'): void
{
    Http::preventStrayRequests();
    Http::fake([
        'api-checkout.cinetpay.com/v2/payment/check' => Http::response([
            'code' => '00',
            'message' => 'SUCCES',
            'data' => array_filter([
                'status' => $status,
                'amount' => (string) $amount,
                'currency' => $currency,
                'operator_id' => $operatorId,
                'payment_method' => 'OM',
            ], static fn ($v): bool => $v !== null),
        ], 200),
    ]);
}

function p3d4Order(array $attributes = [], ?User $user = null): Order
{
    return DB::transaction(function () use ($attributes, $user): Order {
        $order = Order::factory()->create(array_merge([
            'status' => OrderStatus::Pending,
            'total_minor' => 15_000,
            'subtotal_minor' => 15_000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'currency' => 'XOF',
            'user_id' => $user?->id,
            'visitor_id' => null,
            'coupon_id' => null,
            'expires_at' => now()->addMinutes(30),
            'placed_at' => now(),
        ], $attributes));

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => null,
            'product_name_snapshot' => 'Pay Product',
            'product_slug_snapshot' => 'pay-product',
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

function p3d4Payment(Order $order, array $attributes = []): Payment
{
    return DB::transaction(fn (): Payment => Payment::factory()->create(array_merge([
        'public_id' => (string) Str::uuid(),
        'order_id' => $order->id,
        'provider' => 'cinetpay',
        'provider_payment_reference' => null,
        'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
        'attempt_number' => 1,
        'amount_minor' => $order->total_minor,
        'currency' => $order->currency,
        'status' => PaymentStatus::Pending,
        'initiated_at' => now(),
    ], $attributes)));
}

/**
 * Build a CinetPay webhook envelope for a payment, signing it with the test
 * secret over the exact HMAC field order.
 */
function p3d4Webhook(Payment $payment, array $overrides = [], bool $validSignature = true): ProviderWebhookEnvelope
{
    $params = array_merge([
        'cpm_site_id' => '5872868',
        'cpm_trans_id' => (string) $payment->public_id,
        'cpm_trans_date' => '2026-07-23 10:00:00',
        'cpm_amount' => (string) $payment->amount_minor,
        'cpm_currency' => $payment->currency,
        'signature' => 'cinetpay-sig',
        'payment_method' => 'OM',
        'cel_phone_num' => '07000000',
        'cpm_phone_prefixe' => '225',
        'cpm_language' => 'fr',
        'cpm_version' => 'V4',
        'cpm_payment_config' => 'SINGLE',
        'cpm_page_action' => 'PAYMENT',
        'cpm_custom' => '',
        'cpm_designation' => 'order',
        'cpm_error_message' => '',
    ], $overrides);

    $concatenated = '';
    foreach (P3D4_HMAC_FIELDS as $field) {
        $concatenated .= (string) ($params[$field] ?? '');
    }

    $token = $validSignature
        ? hash_hmac('sha256', $concatenated, P3D4_SECRET)
        : 'deadbeef-not-a-valid-token';

    return new ProviderWebhookEnvelope(
        headers: ['x-token' => $token],
        params: $params,
    );
}

beforeEach(function (): void {
    Event::fake([OrderPaid::class]);
});

// ── Coherent success ─────────────────────────────────────────────────────

it('confirms a coherent success: payment succeeded, order paid, event processed', function (): void {
    p3d4FakeCheck('ACCEPTED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    $outcome = p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));

    expect($outcome)->toBe(WebhookOutcome::Accepted);

    $payment->refresh();
    $order->refresh();

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->succeeded_at)->not->toBeNull()
        ->and($payment->processing_at)->not->toBeNull()
        ->and($payment->last_verified_at)->not->toBeNull()
        ->and($payment->provider_payment_reference)->toBe('op_ref_1')
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();

    $event = PaymentWebhookEvent::query()->where('provider', 'cinetpay')->firstOrFail();
    expect($event->processing_status)->toBe(WebhookProcessingStatus::Processed)
        ->and($event->payment_id)->toBe($payment->id)
        ->and($event->signature_verified)->toBeTrue();

    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->orderId === $order->id);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('counter-calls the provider even when the webhook body claims REFUSED', function (): void {
    // The webhook says the payment failed, but the authoritative check says ACCEPTED.
    p3d4FakeCheck('ACCEPTED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment, ['cpm_error_message' => 'REFUSED']));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('treats the server counter-call as authoritative over an ACCEPTED webhook that is really processing', function (): void {
    p3d4FakeCheck('WAITING_FOR_CUSTOMER', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Processing)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderPaid::class);
});

// ── Inconsistent success → review (never false paid, never failed) ────────

it('diverts an amount mismatch to review without consuming anything', function (): void {
    p3d4FakeCheck('ACCEPTED', 14_000); // provider disagrees with the order total
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));

    expect($payment->refresh()->status)->toBe(PaymentStatus::RequiresReview)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReview);
    Event::assertNotDispatched(OrderPaid::class);
});

it('diverts a currency mismatch to review', function (): void {
    p3d4FakeCheck('ACCEPTED', 15_000, 'USD');
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment, ['cpm_currency' => 'USD']));

    expect($payment->refresh()->status)->toBe(PaymentStatus::RequiresReview)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReview);
});

it('diverts a reference conflict to review and never overwrites the stored reference', function (): void {
    p3d4FakeCheck('ACCEPTED', 15_000, 'XOF', 'DIFFERENT_REF');
    $order = p3d4Order();
    $payment = p3d4Payment($order, ['provider_payment_reference' => 'ORIGINAL_REF']);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::RequiresReview)
        ->and($payment->provider_payment_reference)->toBe('ORIGINAL_REF')
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReview);
});

// ── Non-success normalized statuses ──────────────────────────────────────

it('keeps the order pending and consumes nothing on a REFUSED payment', function (): void {
    p3d4FakeCheck('REFUSED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderPaid::class);
});

it('cancels the payment and keeps the order pending on a CANCELLED payment', function (): void {
    p3d4FakeCheck('CANCELLED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});

it('ignores an unknown provider status without any financial mutation', function (): void {
    p3d4FakeCheck('SOMETHING_NEW', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    $event = PaymentWebhookEvent::query()->firstOrFail();
    expect($event->processing_status)->toBe(WebhookProcessingStatus::Ignored);
});

// ── Provider failures during the counter-call ────────────────────────────

it('mutates nothing and fails the event when the counter-call times out', function (): void {
    Http::preventStrayRequests();
    Http::fake(fn () => throw new ConnectionException('timeout'));
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    try {
        p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));
        $this->fail('expected a provider failure');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::ProviderUnavailable);
    }

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    $event = PaymentWebhookEvent::query()->firstOrFail();
    expect($event->processing_status)->toBe(WebhookProcessingStatus::Failed);
    Event::assertNotDispatched(OrderPaid::class);
});

// ── Payment identification ───────────────────────────────────────────────

it('ignores a signed webhook for an unknown payment without revealing anything', function (): void {
    p3d4FakeCheck('ACCEPTED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);
    // Point the webhook at a random, non-existent transaction id.
    $envelope = p3d4Webhook($payment, ['cpm_trans_id' => (string) Str::uuid()]);

    $outcome = p3d4Service()->handleCinetPayWebhook($envelope);

    expect($outcome)->toBe(WebhookOutcome::Accepted)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Pending);
    Event::assertNotDispatched(OrderPaid::class);
});

// ── Coupon consumption ───────────────────────────────────────────────────

function p3d4CouponOrder(Coupon $coupon): Order
{
    return p3d4Order([
        'total_minor' => 12_000,
        'subtotal_minor' => 15_000,
        'discount_minor' => 3_000,
        'coupon_id' => $coupon->id,
        'coupon_code_snapshot' => $coupon->code,
        'coupon_discount_type_snapshot' => 'fixed',
        'coupon_fixed_amount_minor_snapshot' => 3_000,
        'coupon_percent_basis_points_snapshot' => null,
    ]);
}

it('consumes the coupon exactly once on paid, copying the order snapshot', function (): void {
    p3d4FakeCheck('ACCEPTED', 12_000);
    $coupon = DB::transaction(fn () => Coupon::factory()->create([
        'discount_type' => 'fixed', 'percent_basis_points' => null,
        'max_redemptions' => 5, 'redemptions_count' => 0,
    ]));
    $order = p3d4CouponOrder($coupon);
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($coupon->refresh()->redemptions_count)->toBe(1)
        ->and(CouponRedemption::query()->where('order_id', $order->id)->count())->toBe(1);

    $redemption = CouponRedemption::query()->where('order_id', $order->id)->firstOrFail();
    expect($redemption->discount_minor)->toBe(3_000)
        ->and($redemption->coupon_code_snapshot)->toBe($coupon->code);
});

it('diverts to review when the coupon global cap is already reached', function (): void {
    p3d4FakeCheck('ACCEPTED', 12_000);
    $coupon = DB::transaction(fn () => Coupon::factory()->create([
        'discount_type' => 'fixed', 'percent_basis_points' => null,
        'max_redemptions' => 1, 'redemptions_count' => 1, // already full
    ]));
    $order = p3d4CouponOrder($coupon);
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));

    expect($payment->refresh()->status)->toBe(PaymentStatus::RequiresReview)
        ->and($order->refresh()->status)->toBe(OrderStatus::PaymentReview)
        ->and($coupon->refresh()->redemptions_count)->toBe(1)
        ->and(CouponRedemption::query()->where('order_id', $order->id)->exists())->toBeFalse();
});

// ── Idempotent replay of a distinct second notification (C2 shape) ────────

it('is idempotent when a second distinct event arrives for an already paid order', function (): void {
    p3d4FakeCheck('ACCEPTED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment));
    // Second, DISTINCT event (different payload → different external id).
    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($payment, ['cpm_trans_date' => '2026-07-23 11:00:00']));

    expect(Payment::query()->where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1)
        ->and(Order::query()->whereKey($order->id)->where('status', 'paid')->count())->toBe(1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

// ── Real-concurrency proofs on independent runtime connections ───────────

/** An independent runtime connection to the same testing database. */
function p3d4RuntimePdo(): PDO
{
    $c = config('database.connections.pgsql');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']),
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/** Prove a row lock on $table/$id serialises: a second connection times out 55P03. */
function p3d4ProveRowLockSerialises(string $table, int $id): void
{
    $a = p3d4RuntimePdo();
    $b = p3d4RuntimePdo();
    $a->beginTransaction();
    $lockA = $a->prepare("SELECT id FROM {$table} WHERE id = ? FOR UPDATE");
    $lockA->execute([$id]);

    $b->exec("SET lock_timeout = '1000ms'");
    $b->beginTransaction();

    $blocked = null;
    try {
        $lockB = $b->prepare("SELECT id FROM {$table} WHERE id = ? FOR UPDATE");
        $lockB->execute([$id]);
    } catch (PDOException $e) {
        $blocked = $e;
    }

    $b->rollBack();
    $a->rollBack();
    $a = null;
    $b = null; // release both before the service runs

    expect($blocked)->not->toBeNull("The {$table} row lock did not serialise.")
        ->and($blocked->getCode())->toBe('55P03');
}

// C3 — two orders, one coupon place: the coupon lock serialises; the loser is
// diverted to review and the counter is never exceeded.
it('C3 — the coupon last place serialises and the losing paid order goes to review', function (): void {
    p3d4FakeCheck('ACCEPTED', 12_000);
    $coupon = DB::transaction(fn () => Coupon::factory()->create([
        'discount_type' => 'fixed', 'percent_basis_points' => null,
        'max_redemptions' => 1, 'redemptions_count' => 0,
    ]));
    $orderA = p3d4CouponOrder($coupon);
    $paymentA = p3d4Payment($orderA);
    $orderB = p3d4CouponOrder($coupon);
    $paymentB = p3d4Payment($orderB);

    p3d4ProveRowLockSerialises('coupons', $coupon->id);

    // Both external payments really succeeded; only one place exists.
    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($paymentA));
    p3d4Service()->handleCinetPayWebhook(p3d4Webhook($paymentB));

    expect($coupon->refresh()->redemptions_count)->toBe(1)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->id)->count())->toBe(1)
        ->and($orderA->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($orderB->refresh()->status)->toBe(OrderStatus::PaymentReview)
        ->and($paymentB->refresh()->status)->toBe(PaymentStatus::RequiresReview);
    // The winning payment is confirmed exactly once; the loser lost no money.
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

// C4 — the confirmation serialises on the order lock, never deadlocks, and a
// replay is idempotent. No residual 25P02 / 42501.
it('C4 — a confirmation serialises on the order lock and replays idempotently', function (): void {
    p3d4FakeCheck('ACCEPTED', 15_000);
    $order = p3d4Order();
    $payment = p3d4Payment($order);

    p3d4ProveRowLockSerialises('orders', $order->id);

    $envelope = p3d4Webhook($payment);
    p3d4Service()->handleCinetPayWebhook($envelope);
    p3d4Service()->handleCinetPayWebhook($envelope); // exact replay → dedup

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Payment::query()->where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(1)
        ->and(PaymentWebhookEvent::query()->count())->toBe(1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});
