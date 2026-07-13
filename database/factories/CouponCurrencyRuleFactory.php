<?php

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\CouponCurrencyRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CouponCurrencyRule>
 */
class CouponCurrencyRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory()->fixed(),
            'currency' => fake()->randomElement(['XOF', 'EUR', 'USD']),
            'fixed_amount_minor' => fake()->numberBetween(0, 50000),
            'min_order_minor' => fake()->numberBetween(0, 100000),
            'max_discount_minor' => null,
        ];
    }
}
