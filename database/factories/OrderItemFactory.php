<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));
        $slug = Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999);

        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory()->state([
                'name' => $name,
                'slug' => $slug,
                'type' => ProductType::Ebook,
            ]),
            'product_name_snapshot' => $name,
            'product_slug_snapshot' => $slug,
            'product_type_snapshot' => ProductType::Ebook->value,
            'unit_price_minor' => 10000,
            'quantity' => 1,
            'line_subtotal_minor' => 10000,
            'line_discount_minor' => 0,
            'line_total_minor' => 10000,
            'currency' => 'XOF',
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $order->getKey(),
        ]);
    }

    public function forProduct(Product $product): static
    {
        $type = $product->type instanceof ProductType ? $product->type->value : (string) $product->type;

        return $this->state(fn (array $attributes) => [
            'product_id' => $product->getKey(),
            'product_name_snapshot' => $product->name,
            'product_slug_snapshot' => $product->slug,
            'product_type_snapshot' => $type,
        ]);
    }

    public function priced(int $unitPriceMinor, int $quantity = 1, int $discountMinor = 0): static
    {
        $subtotalMinor = $unitPriceMinor * $quantity;

        return $this->state(fn (array $attributes) => [
            'unit_price_minor' => $unitPriceMinor,
            'quantity' => $quantity,
            'line_subtotal_minor' => $subtotalMinor,
            'line_discount_minor' => $discountMinor,
            'line_total_minor' => $subtotalMinor - $discountMinor,
        ]);
    }
}
