<?php

namespace Database\Factories;

use App\Enums\CouponDiscountType;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('PROMO-####-????')),
            'discount_type' => CouponDiscountType::Percent,
            'percent_basis_points' => 1000,
            'max_redemptions' => null,
            'redemptions_count' => 0,
            'max_redemptions_per_customer' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'is_active' => true,
        ];
    }

    public function percent(int $basisPoints = 1000): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => CouponDiscountType::Percent,
            'percent_basis_points' => $basisPoints,
        ]);
    }

    public function fixed(): static
    {
        return $this->state(fn (array $attributes) => [
            'discount_type' => CouponDiscountType::Fixed,
            'percent_basis_points' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }
}
