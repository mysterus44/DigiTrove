<?php

namespace Database\Factories;

use App\Models\DailyFunnelStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyFunnelStat>
 */
class DailyFunnelStatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'day' => today(),
            'visitors' => 10,
            'sessions' => 12,
            'product_views' => 25,
            'add_to_carts' => 5,
            'checkouts' => 3,
            'purchases' => 2,
            'new_customers' => 1,
            'updated_at' => now(),
        ];
    }
}
