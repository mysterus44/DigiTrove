<?php

use App\Enums\ProductType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;

uses(RefreshDatabase::class);

function expectP5A2SnapshotMutationRefused(Closure $callback): void
{
    $exception = null;

    try {
        DB::transaction($callback);
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('23514')
        ->and($exception->getMessage())->toContain('order_items purchased product identity is immutable');
}

it('adds a positive non-null purchased product identity without a catalogue foreign key', function () {
    $column = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'order_items')
        ->where('column_name', 'purchased_product_id')
        ->first();

    expect($column)->not->toBeNull()
        ->and($column->data_type)->toBe('bigint')
        ->and($column->is_nullable)->toBe('NO')
        ->and(Schema::hasColumn('order_items', 'purchased_product_id'))->toBeTrue()
        ->and(DB::table('pg_constraint')
            ->where('conrelid', DB::raw("'public.order_items'::regclass"))
            ->where('contype', 'f')
            ->whereRaw("pg_get_constraintdef(oid) LIKE '%purchased_product_id%'")
            ->count())->toBe(0);
});

it('factories preserve the purchased product identity when the live product disappears', function () {
    $product = Product::factory()->create();
    $order = Order::factory()->create();
    $item = OrderItem::factory()->forOrder($order)->forProduct($product)->create();

    expect($item->purchased_product_id)->toBe($product->id);

    $product->forceDelete();
    $item->refresh();

    expect($item->product_id)->toBeNull()
        ->and($item->purchased_product_id)->toBe($product->id)
        ->and($item->product_name_snapshot)->not->toBe('');
});

it('refuses every direct mutation of the purchased product identity', function () {
    $first = Product::factory()->create();
    $second = Product::factory()->create();
    $item = OrderItem::factory()->forProduct($first)->create();

    expectP5A2SnapshotMutationRefused(
        fn () => DB::table('order_items')->where('id', $item->id)->update([
            'purchased_product_id' => $second->id,
        ]),
    );

    expectP5A2SnapshotMutationRefused(
        fn () => DB::table('order_items')->where('id', $item->id)->update([
            'purchased_product_id' => null,
        ]),
    );

    expect(DB::table('order_items')->where('id', $item->id)->value('purchased_product_id'))->toBe($first->id);
});

it('keeps the purchased bundle as the commercial product rather than a component', function () {
    $bundle = Product::factory()->create(['type' => ProductType::Bundle]);
    $component = Product::factory()->create(['type' => ProductType::Ebook]);
    $item = OrderItem::factory()->forProduct($bundle)->create();

    DB::table('product_bundles')->insert([
        'bundle_id' => $bundle->id,
        'child_product_id' => $component->id,
    ]);
    DB::table('order_item_bundle_components')->insert([
        'order_item_id' => $item->id,
        'child_product_id' => $component->id,
        'child_product_name_snapshot' => $component->name,
        'child_product_slug_snapshot' => $component->slug,
        'created_at' => now(),
    ]);

    expect($item->purchased_product_id)->toBe($bundle->id)
        ->and($item->purchased_product_id)->not->toBe($component->id);
});
