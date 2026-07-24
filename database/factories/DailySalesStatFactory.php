<?php

namespace Database\Factories;

use App\Models\DailySalesStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailySalesStat>
 */
class DailySalesStatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'day' => today(),
            'currency' => 'XOF',
            'orders_count' => 2,
            'gross_revenue_minor' => 22000,
            'discount_minor' => 2000,
            'tax_minor' => 1000,
            'refunds_minor' => 3000,
            'net_revenue_minor' => 18000,
            'average_order_minor' => 10500,
            'updated_at' => now(),
        ];
    }
}
