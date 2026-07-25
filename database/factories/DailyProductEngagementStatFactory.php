<?php

namespace Database\Factories;

use App\Models\DailyProductEngagementStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyProductEngagementStat>
 */
class DailyProductEngagementStatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'day' => today(),
            'product_id' => fake()->numberBetween(1, 100000),
            'views' => 12,
            'add_to_carts' => 0,
            'updated_at' => now(),
        ];
    }
}
