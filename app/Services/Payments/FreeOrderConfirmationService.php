<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Events\OrderPaid;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use App\Support\CustomerRedemptionKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finalises a free order (`total_minor = 0`) to `paid` with no Payment row
 * (P3-D4/P3-D5, D-034).
 *
 * A free order never carries a payment (the DB trigger enforces this too), so
 * there is no provider to counter-verify: the transition is purely local, plus
 * optional coupon consumption. OrderPaid fires once, after COMMIT, only on a
 * real `pending → paid` transition. Ownership resolution is anti-enumeration and
 * identical to P3-D2/P3-D3.
 */
final class FreeOrderConfirmationService
{
    public function confirm(User|Visitor $actor, string $orderPublicId, ?CarbonImmutable $at = null): Order
    {
        if (DB::transactionLevel() !== 0) {
            throw self::integrity();
        }

        $now = $at ?? CarbonImmutable::now();

        try {
            $order = DB::transaction(function () use ($actor, $orderPublicId, $now): Order {
                $order = Order::query()->where('public_id', $orderPublicId)->lockForUpdate()->first();

                if ($order === null || ! $this->owns($actor, $order)) {
                    throw PaymentConfirmationException::of(
                        Reason::FreeOrderInvalid,
                        'This order is not available.',
                    );
                }

                // Idempotent replay: an already-paid free order is returned as-is,
                // with no second redemption and no second dispatch.
                if ($order->status === OrderStatus::Paid) {
                    return $order;
                }

                if ((int) $order->total_minor !== 0) {
                    throw PaymentConfirmationException::of(Reason::FreeOrderInvalid, 'This order is not a free order.');
                }

                if ($order->status !== OrderStatus::Pending) {
                    throw PaymentConfirmationException::of(Reason::FreeOrderInvalid, 'This order can no longer be finalised.');
                }

                // A free order must never have a payment (the trigger agrees).
                if (Payment::query()->where('order_id', $order->id)->exists()) {
                    throw self::integrity();
                }

                // A scoped coupon that can no longer be consumed rolls the whole
                // finalisation back — a free order has no review path.
                $this->consumeCouponOrFail($order, $now);

                $order->forceFill(['status' => OrderStatus::Paid->value, 'paid_at' => $now])->save();

                $orderId = $order->id;
                DB::afterCommit(static function () use ($orderId): void {
                    event(new OrderPaid($orderId));
                });

                return $order;
            });
        } catch (PaymentConfirmationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::integrity();
        }

        return $order;
    }

    private function consumeCouponOrFail(Order $order, CarbonImmutable $now): void
    {
        if ($order->coupon_id === null) {
            return;
        }

        if (CouponRedemption::query()->where('order_id', $order->id)->exists()) {
            return;
        }

        $coupon = Coupon::query()->whereKey($order->coupon_id)->lockForUpdate()->first();

        $capReached = $coupon === null
            || ($coupon->max_redemptions !== null && $coupon->redemptions_count >= $coupon->max_redemptions);

        if ($capReached) {
            throw PaymentConfirmationException::of(Reason::CouponUnavailable, 'The coupon is no longer available.');
        }

        $customerKey = CustomerRedemptionKey::forOrder($order);

        if ($coupon->max_redemptions_per_customer !== null) {
            $used = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('customer_key_version', CustomerRedemptionKey::VERSION)
                ->where('customer_key_hash', $customerKey)
                ->count();

            if ($used >= $coupon->max_redemptions_per_customer) {
                throw PaymentConfirmationException::of(Reason::CouponUnavailable, 'The coupon is no longer available.');
            }
        }

        CouponRedemption::query()->create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'customer_key_version' => CustomerRedemptionKey::VERSION,
            'customer_key_hash' => $customerKey,
            'coupon_code_snapshot' => $order->coupon_code_snapshot,
            'discount_type_snapshot' => $order->coupon_discount_type_snapshot,
            'discount_minor' => $order->discount_minor,
            'currency' => $order->currency,
            'redeemed_at' => $now,
        ]);

        $coupon->forceFill(['redemptions_count' => $coupon->redemptions_count + 1])->save();
    }

    private function owns(User|Visitor $actor, Order $order): bool
    {
        return $actor instanceof User
            ? $order->user_id !== null && $order->user_id === $actor->id
            : $order->user_id === null && $order->visitor_id !== null && $order->visitor_id === $actor->id;
    }

    private static function integrity(): PaymentConfirmationException
    {
        return PaymentConfirmationException::of(
            Reason::IntegrityFailure,
            'The order could not be finalised.',
        );
    }
}
