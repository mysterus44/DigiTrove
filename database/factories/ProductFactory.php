<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => ucfirst($name),
            'type' => ProductType::Ebook,
            'status' => ProductStatus::Draft,
            'short_description' => fake()->sentence(),
            'long_description' => fake()->paragraph(),
            'cover_image_path' => 'images/digitrove/products/placeholder.png',
            'meta_title' => fake()->sentence(4),
            'meta_description' => fake()->sentence(10),
            'sales_count' => 0,
            'rating_avg' => 0,
            'rating_count' => 0,
            'published_at' => null,
        ];
    }

    public function bundle(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductType::Bundle,
        ]);
    }
}
