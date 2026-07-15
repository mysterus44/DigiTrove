<?php

namespace Database\Factories;

use App\Enums\CartStatus;
use App\Models\Cart;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'secret_hash' => hash('sha256', 'fixture-'.Str::uuid()),
            'visitor_id' => null,
            'user_id' => null,
            'coupon_id' => null,
            'currency' => null,
            'status' => CartStatus::Active,
            'expires_at' => now()->addDays(7),
            'abandoned_at' => null,
        ];
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CartStatus::Converted,
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CartStatus::Abandoned,
            'abandoned_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CartStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }
}
