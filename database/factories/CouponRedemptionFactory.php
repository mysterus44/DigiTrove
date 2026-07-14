<?php

namespace Database\Factories;

use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Persist this factory inside the same transaction as its order and order items.
 *
 * @extends Factory<CouponRedemption>
 */
class CouponRedemptionFactory extends Factory
{
    public function definition(): array
    {
        $couponCode = 'FACTORY-10';

        $order = Order::factory()
            ->paid()
            ->state([
                'coupon_id' => null,
                'coupon_code_snapshot' => $couponCode,
                'coupon_discount_type_snapshot' => 'percent',
                'coupon_percent_basis_points_snapshot' => 1000,
                'coupon_fixed_amount_minor_snapshot' => null,
                'subtotal_minor' => 10000,
                'discount_minor' => 1000,
                'total_minor' => 9000,
            ])
            ->has(OrderItem::factory()->state([
                'line_discount_minor' => 1000,
                'line_total_minor' => 9000,
            ]), 'items');

        return [
            'coupon_id' => null,
            'order_id' => $order,
            'customer_key_version' => 1,
            'customer_key_hash' => hash('sha256', 'fixture-customer-'.Str::uuid()),
            'coupon_code_snapshot' => $couponCode,
            'discount_type_snapshot' => 'percent',
            'discount_minor' => 1000,
            'currency' => 'XOF',
            'redeemed_at' => now(),
        ];
    }
}
