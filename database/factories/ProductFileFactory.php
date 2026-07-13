<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductFile>
 */
class ProductFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'storage_disk' => 'private',
            'storage_path' => 'products/'.fake()->uuid().'/readme.txt',
            'original_name' => 'readme.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => fake()->numberBetween(1, 1000000),
            'checksum_sha256' => hash('sha256', fake()->uuid()),
            'version' => '1.0',
            'position' => 0,
            'is_active' => true,
            'created_at' => now(),
        ];
    }
}
