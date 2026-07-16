<?php

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\OrderItemBundleComponent;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds only coherent purchase snapshots.
 *
 * The default state creates a real bundle purchase graph (bundle product, one
 * non-bundle component, the pivot row linking them, and a bundle order_item), so
 * the produced row satisfies S3. States never attach, detach or edit a pivot row
 * that already exists, never touch the order_item, and never invent a component
 * link: an invalid combination must be rejected by the database, not smuggled in.
 *
 * @extends Factory<OrderItemBundleComponent>
 */
class OrderItemBundleComponentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_item_id' => fn (): int => $this->newBundlePurchaseOrderItem()->getKey(),
            'child_product_id' => fn (array $attributes): int => $this->resolveAttachedComponentId((int) $attributes['order_item_id']),
            'child_product_name_snapshot' => fn (array $attributes): string => $this->componentField((int) $attributes['child_product_id'], 'name'),
            'child_product_slug_snapshot' => fn (array $attributes): string => $this->componentField((int) $attributes['child_product_id'], 'slug'),
            'created_at' => now(),
        ];
    }

    /**
     * Snapshot an existing bundle order_item. The caller owns the pivot: this state
     * never attaches the component itself.
     */
    public function forOrderItem(OrderItem $orderItem): static
    {
        return $this->state(fn (array $attributes) => [
            'order_item_id' => $orderItem->getKey(),
        ]);
    }

    /**
     * Snapshot a specific component, copying its name and slug as purchased.
     */
    public function forComponent(Product $component): static
    {
        return $this->state(fn (array $attributes) => [
            'child_product_id' => $component->getKey(),
            'child_product_name_snapshot' => $component->name,
            'child_product_slug_snapshot' => $component->slug,
        ]);
    }

    /**
     * A bundle order_item whose bundle already owns exactly one attached component.
     */
    private function newBundlePurchaseOrderItem(): OrderItem
    {
        $bundle = Product::factory()->bundle()->create();
        $component = Product::factory()->create();
        $bundle->childProducts()->attach($component->getKey(), ['position' => 0]);

        return OrderItem::factory()->forProduct($bundle)->create();
    }

    private function resolveAttachedComponentId(int $orderItemId): int
    {
        $orderItem = OrderItem::query()->findOrFail($orderItemId);

        $componentId = DB::table('product_bundles')
            ->where('bundle_id', $orderItem->product_id)
            ->orderBy('position')
            ->orderBy('child_product_id')
            ->value('child_product_id');

        if ($componentId === null) {
            throw new RuntimeException(
                'OrderItemBundleComponentFactory needs a component already attached to the purchased bundle; '
                .'attach one first or pass forComponent().',
            );
        }

        return (int) $componentId;
    }

    private function componentField(int $componentId, string $field): string
    {
        return (string) Product::query()->findOrFail($componentId)->{$field};
    }
}
