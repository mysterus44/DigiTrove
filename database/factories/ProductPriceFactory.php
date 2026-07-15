<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPrice>
 */
class ProductPriceFactory extends Factory
{
    public function definition(): array
    {
        $price = fake()->numberBetween(1000, 250000);

        return [
            'product_id' => Product::factory(),
            'currency' => 'XOF',
            'price_minor' => $price,
            'compare_at_price_minor' => $price + fake()->numberBetween(1000, 50000),
            'is_active' => true,
        ];
    }
}
