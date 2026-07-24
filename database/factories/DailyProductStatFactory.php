<?php

namespace Database\Factories;

use App\Models\DailyProductStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyProductStat>
 */
class DailyProductStatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'day' => today(),
            'product_id' => fake()->numberBetween(1, 100000),
            'currency' => 'XOF',
            'views' => 12,
            'add_to_carts' => 4,
            'purchases' => 2,
            'revenue_minor' => 5000,
            'updated_at' => now(),
        ];
    }
}
