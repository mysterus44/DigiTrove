<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Payments\FreeOrderConfirmationService;
use App\Services\Payments\PaymentConfirmationException;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| P3-D5 — OrderPaid event + free-order finalisation (D-034)
|--------------------------------------------------------------------------
*/

function p3d5FreeService(): FreeOrderConfirmationService
{
    return new FreeOrderConfirmationService;
}

function p3d5FreeOrder(User|Visitor $actor, array $attributes = []): Order
{
    $isUser = $actor instanceof User;

    return p3d4Order(array_merge([
        'total_minor' => 0,
        'subtotal_minor' => 0,
        'discount_minor' => 0,
        'user_id' => $isUser ? $actor->id : null,
        'visitor_id' => $isUser ? null : $actor->id,
    ], $attributes));
}

beforeEach(function (): void {
    Event::fake([OrderPaid::class]);
});

// ── OrderPaid payload ────────────────────────────────────────────────────

it('OrderPaid carries only the integer order id', function (): void {
    $reflection = new ReflectionClass(OrderPaid::class);
    $params = $reflection->getConstructor()->getParameters();

    expect($params)->toHaveCount(1)
        ->and($params[0]->getName())->toBe('orderId')
        ->and((string) $params[0]->getType())->toBe('int')
        ->and((new OrderPaid(42))->orderId)->toBe(42);
});

// ── Free order finalisation ──────────────────────────────────────────────

it('finalises a free order to paid with no payment and one OrderPaid', function (): void {
    $user = DB::transaction(fn () => User::factory()->create());
    $order = p3d5FreeOrder($user);

    $result = p3d5FreeService()->confirm($user, (string) $order->public_id);

    expect($result->status)->toBe(OrderStatus::Paid)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(0);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e): bool => $e->orderId === $order->id);
});

it('consumes a coupon exactly once on a free order (C5 replay stays idempotent)', function (): void {
    $user = DB::transaction(fn () => User::factory()->create());
    $coupon = DB::transaction(fn () => Coupon::factory()->create([
        'discount_type' => 'fixed', 'percent_basis_points' => null,
        'max_redemptions' => 3, 'redemptions_count' => 0,
    ]));
    $order = p3d5FreeOrder($user, [
        'subtotal_minor' => 5_000,
        'discount_minor' => 5_000,
        'total_minor' => 0,
        'coupon_id' => $coupon->id,
        'coupon_code_snapshot' => $coupon->code,
        'coupon_discount_type_snapshot' => 'fixed',
        'coupon_fixed_amount_minor_snapshot' => 5_000,
    ]);

    p3d5FreeService()->confirm($user, (string) $order->public_id);
    // Replay: already paid → idempotent.
    p3d5FreeService()->confirm($user, (string) $order->public_id);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(CouponRedemption::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and($coupon->refresh()->redemptions_count)->toBe(1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('uses uniform anti-enumeration for an unknown or foreign order', function (): void {
    $user = DB::transaction(fn () => User::factory()->create());
    $stranger = DB::transaction(fn () => User::factory()->create());
    $order = p3d5FreeOrder($stranger);

    // Unknown public id.
    expect(fn () => p3d5FreeService()->confirm($user, (string) Str::uuid()))
        ->toThrow(PaymentConfirmationException::class);
    // Foreign order → same refusal, no dispatch.
    expect(fn () => p3d5FreeService()->confirm($user, (string) $order->public_id))
        ->toThrow(PaymentConfirmationException::class);

    Event::assertNotDispatched(OrderPaid::class);
});

it('refuses a non-free order as FreeOrderInvalid', function (): void {
    $user = DB::transaction(fn () => User::factory()->create());
    $order = p3d4Order(['user_id' => $user->id, 'total_minor' => 15_000, 'subtotal_minor' => 15_000]);

    try {
        p3d5FreeService()->confirm($user, (string) $order->public_id);
        $this->fail('expected a refusal');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::FreeOrderInvalid);
    }
    Event::assertNotDispatched(OrderPaid::class);
});

it('finalises a free order for a visitor actor with uniform ownership', function (): void {
    $visitor = DB::transaction(fn () => Visitor::factory()->create());
    $order = p3d5FreeOrder($visitor);

    $result = p3d5FreeService()->confirm($visitor, (string) $order->public_id);

    expect($result->status)->toBe(OrderStatus::Paid)
        ->and(Payment::query()->where('order_id', $order->id)->count())->toBe(0);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('rolls the free order back and dispatches nothing when a coupon is unavailable', function (): void {
    $user = DB::transaction(fn () => User::factory()->create());
    $coupon = DB::transaction(fn () => Coupon::factory()->create([
        'discount_type' => 'fixed', 'percent_basis_points' => null,
        'max_redemptions' => 1, 'redemptions_count' => 1, // full
    ]));
    $order = p3d5FreeOrder($user, [
        'subtotal_minor' => 5_000,
        'discount_minor' => 5_000,
        'total_minor' => 0,
        'coupon_id' => $coupon->id,
        'coupon_code_snapshot' => $coupon->code,
        'coupon_discount_type_snapshot' => 'fixed',
        'coupon_fixed_amount_minor_snapshot' => 5_000,
    ]);

    try {
        p3d5FreeService()->confirm($user, (string) $order->public_id);
        $this->fail('expected a coupon refusal');
    } catch (PaymentConfirmationException $e) {
        expect($e->reason)->toBe(Reason::CouponUnavailable);
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(CouponRedemption::query()->where('order_id', $order->id)->exists())->toBeFalse();
    Event::assertNotDispatched(OrderPaid::class);
});
