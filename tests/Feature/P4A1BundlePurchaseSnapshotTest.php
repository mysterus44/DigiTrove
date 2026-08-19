<?php

use App\Enums\ProductType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemBundleComponent;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

function expectP4A1QueryException(Closure $callback, string $sqlState, string $messageFragment): void
{
    $exception = null;

    try {
        DB::transaction($callback);
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe($sqlState)
        ->and($exception->getMessage())->toContain($messageFragment);
}

function expectP4A1TriggerViolation(Closure $callback, string $messageFragment): void
{
    expectP4A1QueryException($callback, '23514', $messageFragment);
}

/**
 * A coherent bundle purchase: bundle product, attached components, and the
 * bundle order_item, all created inside one transaction so the P3B deferred
 * order/items triggers see a complete commercial snapshot.
 *
 * @param  int  $componentCount  number of non-bundle components attached to the bundle
 * @return array{bundle: Product, components: array<int, Product>, order: Order, item: OrderItem}
 */
function createP4A1BundlePurchase(int $componentCount = 1): array
{
    return DB::transaction(function () use ($componentCount): array {
        $bundle = Product::factory()->bundle()->create();
        $components = [];

        for ($position = 0; $position < $componentCount; $position++) {
            $component = Product::factory()->create();
            $bundle->childProducts()->attach($component->getKey(), ['position' => $position]);
            $components[] = $component;
        }

        $order = Order::factory()->create();
        $item = OrderItem::factory()->forOrder($order)->forProduct($bundle)->create();

        return compact('bundle', 'components', 'order', 'item');
    });
}

/**
 * @return array{order: Order, item: OrderItem, product: Product}
 */
function createP4A1DirectPurchase(): array
{
    return DB::transaction(function (): array {
        $product = Product::factory()->create();
        $order = Order::factory()->create();
        $item = OrderItem::factory()->forOrder($order)->forProduct($product)->create();

        return compact('order', 'item', 'product');
    });
}

function snapshotP4A1(OrderItem $item, Product $component): OrderItemBundleComponent
{
    return OrderItemBundleComponent::factory()
        ->forOrderItem($item)
        ->forComponent($component)
        ->create();
}

it('applies migration 000009 with the exact physical schema, three functions and three triggers', function () {
    expect(DB::table('migrations')->where('migration', '2026_07_14_000009_create_order_item_bundle_components_table')->exists())->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(50)
        ->and(Schema::hasTable('order_item_bundle_components'))->toBeTrue();

    $columns = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'order_item_bundle_components')
        ->pluck('is_nullable', 'column_name');

    expect($columns->keys()->sort()->values()->all())->toBe([
        'child_product_id',
        'child_product_name_snapshot',
        'child_product_slug_snapshot',
        'created_at',
        'id',
        'order_item_id',
    ]);

    $types = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'order_item_bundle_components')
        ->pluck('data_type', 'column_name');

    expect($types['id'])->toBe('bigint')
        ->and($types['order_item_id'])->toBe('bigint')
        ->and($types['child_product_id'])->toBe('bigint')
        ->and($types['child_product_name_snapshot'])->toBe('text')
        ->and($types['child_product_slug_snapshot'])->toBe('text')
        ->and($types['created_at'])->toBe('timestamp with time zone')
        ->and($columns['order_item_id'])->toBe('NO')
        ->and($columns['child_product_id'])->toBe('YES')
        ->and($columns['child_product_name_snapshot'])->toBe('NO')
        ->and($columns['child_product_slug_snapshot'])->toBe('NO')
        ->and($columns['created_at'])->toBe('NO');

    // No quantity, no position, no updated_at, no jsonb, no metadata.
    foreach (['quantity', 'position', 'updated_at', 'metadata', 'product_file_id'] as $forbidden) {
        expect(Schema::hasColumn('order_item_bundle_components', $forbidden))->toBeFalse("Unexpected column: {$forbidden}");
    }

    $foreignKeys = DB::table('pg_constraint')
        ->whereRaw("conrelid = 'order_item_bundle_components'::regclass")
        ->where('contype', 'f')
        ->pluck('confdeltype', 'conname');

    // 'r' = RESTRICT, 'n' = SET NULL
    expect($foreignKeys['order_item_bundle_components_order_item_id_foreign'])->toBe('r')
        ->and($foreignKeys['order_item_bundle_components_child_product_id_foreign'])->toBe('n');

    expect(DB::table('pg_constraint')->whereRaw("conrelid = 'order_item_bundle_components'::regclass")->where('conname', 'oibc_child_name_not_blank_check')->exists())->toBeTrue()
        ->and(DB::table('pg_constraint')->whereRaw("conrelid = 'order_item_bundle_components'::regclass")->where('conname', 'oibc_child_slug_not_blank_check')->exists())->toBeTrue();

    $indexes = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'order_item_bundle_components')
        ->pluck('indexdef', 'indexname');

    expect($indexes)->toHaveKey('oibc_order_item_child_unique')
        ->and($indexes['oibc_order_item_child_unique'])->toContain('UNIQUE')
        ->and($indexes['oibc_order_item_child_unique'])->toContain('WHERE (child_product_id IS NOT NULL)')
        ->and($indexes)->toHaveKey('oibc_order_item_id_index')
        ->and($indexes)->toHaveKey('oibc_child_product_id_index');

    $functions = ['prevent_order_item_bundle_components_delete', 'enforce_order_item_bundle_component_immutability', 'validate_order_item_bundle_component'];
    expect(DB::table('pg_proc')->whereIn('proname', $functions)->count())->toBe(3);

    $triggers = DB::table('information_schema.triggers')
        ->where('event_object_table', 'order_item_bundle_components')
        ->get(['trigger_name', 'action_timing', 'event_manipulation'])
        ->keyBy('trigger_name');

    expect($triggers)->toHaveCount(3)
        ->and($triggers['order_item_bundle_components_prevent_delete_trigger']->action_timing)->toBe('BEFORE')
        ->and($triggers['order_item_bundle_components_prevent_delete_trigger']->event_manipulation)->toBe('DELETE')
        ->and($triggers['order_item_bundle_components_enforce_immutability_trigger']->action_timing)->toBe('BEFORE')
        ->and($triggers['order_item_bundle_components_enforce_immutability_trigger']->event_manipulation)->toBe('UPDATE')
        ->and($triggers['order_item_bundle_components_validate_insert_trigger']->action_timing)->toBe('BEFORE')
        ->and($triggers['order_item_bundle_components_validate_insert_trigger']->event_manipulation)->toBe('INSERT');

    // No deferred constraint trigger, no S4, no cardinality guard: exhaustiveness is
    // deliberately NOT a database guarantee (D-029.3).
    $deferrable = DB::table('pg_trigger')
        ->whereRaw("tgrelid = 'order_item_bundle_components'::regclass")
        ->where('tgisinternal', false)
        ->where('tgdeferrable', true)
        ->count();

    expect($deferrable)->toBe(0);
});

it('accepts a valid snapshot for one and for several components, with faithful name and slug', function () {
    $purchase = createP4A1BundlePurchase(3);

    foreach ($purchase['components'] as $component) {
        $snapshot = snapshotP4A1($purchase['item'], $component);

        expect($snapshot->child_product_id)->toBe($component->id)
            ->and($snapshot->child_product_name_snapshot)->toBe($component->name)
            ->and($snapshot->child_product_slug_snapshot)->toBe($component->slug);
    }

    expect($purchase['item']->bundleComponents()->count())->toBe(3)
        ->and($purchase['item']->bundleComponents->first()->orderItem->is($purchase['item']))->toBeTrue()
        ->and($purchase['item']->bundleComponents->first()->childProduct->is($purchase['components'][0]))->toBeTrue();
});

it('produces a valid snapshot from the default factory state and from either state order', function () {
    $default = OrderItemBundleComponent::factory()->create();

    expect($default->exists)->toBeTrue()
        ->and($default->orderItem->product_type_snapshot)->toBe(ProductType::Bundle->value)
        ->and($default->childProduct->type)->not->toBe(ProductType::Bundle);

    // State order must not change the outcome.
    $first = createP4A1BundlePurchase();
    $second = createP4A1BundlePurchase();

    $a = OrderItemBundleComponent::factory()->forOrderItem($first['item'])->forComponent($first['components'][0])->create();
    $b = OrderItemBundleComponent::factory()->forComponent($second['components'][0])->forOrderItem($second['item'])->create();

    expect($a->child_product_id)->toBe($first['components'][0]->id)
        ->and($a->child_product_name_snapshot)->toBe($first['components'][0]->name)
        ->and($b->child_product_id)->toBe($second['components'][0]->id)
        ->and($b->child_product_name_snapshot)->toBe($second['components'][0]->name);
});

it('allows the same component in different order items and different orders', function () {
    $shared = Product::factory()->create();

    $purchases = [];

    foreach (range(1, 2) as $ignored) {
        $purchases[] = DB::transaction(function () use ($shared): array {
            $bundle = Product::factory()->bundle()->create();
            $bundle->childProducts()->attach($shared->getKey(), ['position' => 0]);
            $order = Order::factory()->create();
            $item = OrderItem::factory()->forOrder($order)->forProduct($bundle)->create();

            return compact('order', 'item');
        });
    }

    snapshotP4A1($purchases[0]['item'], $shared);
    snapshotP4A1($purchases[1]['item'], $shared);

    expect(DB::table('order_item_bundle_components')->where('child_product_id', $shared->id)->count())->toBe(2)
        ->and($purchases[0]['order']->is($purchases[1]['order']))->toBeFalse();
});

it('refuses a snapshot on a direct order item and leaves direct purchases snapshot-free', function () {
    $direct = createP4A1DirectPurchase();

    expect($direct['item']->bundleComponents()->count())->toBe(0);

    $component = Product::factory()->create();

    expectP4A1TriggerViolation(
        fn () => OrderItemBundleComponent::factory()->forOrderItem($direct['item'])->forComponent($component)->create(),
        'order_item_bundle_components require a bundle order_item',
    );

    expect(DB::table('order_item_bundle_components')->count())->toBe(0);
});

it('refuses every S3 violation with a stable message and never rewrites values', function () {
    $purchase = createP4A1BundlePurchase();
    $component = $purchase['components'][0];

    // child_product_id required at insert.
    expectP4A1TriggerViolation(
        fn () => DB::table('order_item_bundle_components')->insert([
            'order_item_id' => $purchase['item']->id,
            'child_product_id' => null,
            'child_product_name_snapshot' => 'Orphan',
            'child_product_slug_snapshot' => 'orphan',
            'created_at' => now(),
        ]),
        'order_item_bundle_components require a child product at insert',
    );

    // Component of another bundle is not part of this purchase.
    $otherPurchase = createP4A1BundlePurchase();
    expectP4A1TriggerViolation(
        fn () => snapshotP4A1($purchase['item'], $otherPurchase['components'][0]),
        'order_item_bundle_components component must belong to the purchased bundle',
    );

    // Component never attached anywhere.
    $unattached = Product::factory()->create();
    expectP4A1TriggerViolation(
        fn () => snapshotP4A1($purchase['item'], $unattached),
        'order_item_bundle_components component must belong to the purchased bundle',
    );

    // Nested bundle component is excluded (D-029.3).
    $nested = Product::factory()->bundle()->create();
    $purchase['bundle']->childProducts()->attach($nested->getKey(), ['position' => 9]);
    expectP4A1TriggerViolation(
        fn () => snapshotP4A1($purchase['item'], $nested),
        'order_item_bundle_components exclude nested bundle components',
    );

    // Unknown order_item / unknown product: the foreign key stays the authority.
    expectP4A1QueryException(
        fn () => DB::table('order_item_bundle_components')->insert([
            'order_item_id' => 999999,
            'child_product_id' => $component->id,
            'child_product_name_snapshot' => $component->name,
            'child_product_slug_snapshot' => $component->slug,
            'created_at' => now(),
        ]),
        '23503',
        'order_item_bundle_components_order_item_id_foreign',
    );

    expectP4A1QueryException(
        fn () => DB::table('order_item_bundle_components')->insert([
            'order_item_id' => $purchase['item']->id,
            'child_product_id' => 999999,
            'child_product_name_snapshot' => 'Ghost',
            'child_product_slug_snapshot' => 'ghost',
            'created_at' => now(),
        ]),
        '23503',
        'order_item_bundle_components_child_product_id_foreign',
    );

    expect(DB::table('order_item_bundle_components')->count())->toBe(0);

    // A valid row still passes, unmodified by S3.
    $snapshot = snapshotP4A1($purchase['item'], $component);
    $stored = DB::table('order_item_bundle_components')->where('id', $snapshot->id)->first();

    expect($stored->order_item_id)->toBe($purchase['item']->id)
        ->and($stored->child_product_id)->toBe($component->id)
        ->and($stored->child_product_name_snapshot)->toBe($component->name)
        ->and($stored->child_product_slug_snapshot)->toBe($component->slug);
});

it('refuses a bundle order item whose product was physically purged', function () {
    $purchase = createP4A1BundlePurchase();
    $component = $purchase['components'][0];

    // Physically deleting the bundle nulls order_items.product_id (P3B SET NULL).
    DB::table('products')->where('id', $purchase['bundle']->id)->delete();

    expect(DB::table('order_items')->where('id', $purchase['item']->id)->value('product_id'))->toBeNull();

    expectP4A1TriggerViolation(
        fn () => DB::table('order_item_bundle_components')->insert([
            'order_item_id' => $purchase['item']->id,
            'child_product_id' => $component->id,
            'child_product_name_snapshot' => $component->name,
            'child_product_slug_snapshot' => $component->slug,
            'created_at' => now(),
        ]),
        'order_item_bundle_components require a resolvable bundle product',
    );
});

it('refuses a duplicated component for the same order item through the partial unique index', function () {
    $purchase = createP4A1BundlePurchase();
    $component = $purchase['components'][0];

    snapshotP4A1($purchase['item'], $component);

    expectP4A1QueryException(
        fn () => snapshotP4A1($purchase['item'], $component),
        '23505',
        'oibc_order_item_child_unique',
    );

    expect(DB::table('order_item_bundle_components')->count())->toBe(1);
});

it('refuses blank or whitespace-only name and slug snapshots', function () {
    $purchase = createP4A1BundlePurchase();
    $component = $purchase['components'][0];

    $rawRow = fn (array $overrides): array => array_merge([
        'order_item_id' => $purchase['item']->id,
        'child_product_id' => $component->id,
        'child_product_name_snapshot' => $component->name,
        'child_product_slug_snapshot' => $component->slug,
        'created_at' => now(),
    ], $overrides);

    expectP4A1QueryException(fn () => DB::table('order_item_bundle_components')->insert($rawRow(['child_product_name_snapshot' => ''])), '23514', 'oibc_child_name_not_blank_check');
    expectP4A1QueryException(fn () => DB::table('order_item_bundle_components')->insert($rawRow(['child_product_name_snapshot' => '   '])), '23514', 'oibc_child_name_not_blank_check');
    expectP4A1QueryException(fn () => DB::table('order_item_bundle_components')->insert($rawRow(['child_product_slug_snapshot' => ''])), '23514', 'oibc_child_slug_not_blank_check');
    expectP4A1QueryException(fn () => DB::table('order_item_bundle_components')->insert($rawRow(['child_product_slug_snapshot' => "\t \n"])), '23514', 'oibc_child_slug_not_blank_check');

    // NOT NULL closes the NULL bypass of the CHECK.
    expectP4A1QueryException(fn () => DB::table('order_item_bundle_components')->insert($rawRow(['child_product_name_snapshot' => null])), '23502', 'child_product_name_snapshot');

    expect(DB::table('order_item_bundle_components')->count())->toBe(0);
});

it('refuses every physical deletion of a purchase snapshot', function () {
    $purchase = createP4A1BundlePurchase(2);
    $first = snapshotP4A1($purchase['item'], $purchase['components'][0]);
    snapshotP4A1($purchase['item'], $purchase['components'][1]);

    $message = 'order_item_bundle_components are purchase evidence and cannot be deleted';

    expectP4A1TriggerViolation(fn () => DB::table('order_item_bundle_components')->where('id', $first->id)->delete(), $message);
    expectP4A1TriggerViolation(fn () => DB::table('order_item_bundle_components')->whereNotNull('id')->delete(), $message);
    expectP4A1TriggerViolation(fn () => $first->fresh()->delete(), $message);
    expectP4A1TriggerViolation(fn () => $purchase['item']->bundleComponents()->delete(), $message);

    expect(DB::table('order_item_bundle_components')->count())->toBe(2);
});

it('freezes every snapshot column while accepting a strictly identical assignment', function () {
    $purchase = createP4A1BundlePurchase();
    $other = createP4A1BundlePurchase();
    $snapshot = snapshotP4A1($purchase['item'], $purchase['components'][0]);
    $original = DB::table('order_item_bundle_components')->where('id', $snapshot->id)->first();

    $message = 'order_item_bundle_components purchase snapshot is immutable';

    $forbidden = [
        'id' => $snapshot->id + 500,
        'order_item_id' => $other['item']->id,
        'child_product_name_snapshot' => 'Renamed component',
        'child_product_slug_snapshot' => 'renamed-component',
        'created_at' => now()->addDay(),
    ];

    foreach ($forbidden as $column => $value) {
        expectP4A1TriggerViolation(
            fn () => DB::table('order_item_bundle_components')->where('id', $snapshot->id)->update([$column => $value]),
            $message,
        );
    }

    // Multi-column update mixing frozen columns is refused atomically.
    expectP4A1TriggerViolation(
        fn () => DB::table('order_item_bundle_components')->where('id', $snapshot->id)->update([
            'child_product_name_snapshot' => 'Sneaky',
            'child_product_slug_snapshot' => 'sneaky',
        ]),
        $message,
    );

    // Eloquent is refused exactly like raw SQL.
    expectP4A1TriggerViolation(
        fn () => $snapshot->fresh()->update(['child_product_slug_snapshot' => 'eloquent-rename']),
        $message,
    );

    expect(DB::table('order_item_bundle_components')->where('id', $snapshot->id)->first())->toEqual($original);

    // Re-assigning every column to its identical value is accepted.
    $affected = DB::table('order_item_bundle_components')->where('id', $snapshot->id)->update([
        'id' => $original->id,
        'order_item_id' => $original->order_item_id,
        'child_product_id' => $original->child_product_id,
        'child_product_name_snapshot' => $original->child_product_name_snapshot,
        'child_product_slug_snapshot' => $original->child_product_slug_snapshot,
        'created_at' => $original->created_at,
    ]);

    expect($affected)->toBe(1)
        ->and(DB::table('order_item_bundle_components')->where('id', $snapshot->id)->first())->toEqual($original);
});

it('nulls the component reference only through the foreign key action, never by a manual update', function () {
    $purchase = createP4A1BundlePurchase();
    $component = $purchase['components'][0];
    $snapshot = snapshotP4A1($purchase['item'], $component);
    $replacement = Product::factory()->create();

    $message = 'order_item_bundle_components child product reference may only be nulled by the foreign key action';

    // A direct UPDATE runs at trigger depth 1: it cannot impersonate ON DELETE SET NULL.
    expectP4A1TriggerViolation(
        fn () => DB::table('order_item_bundle_components')->where('id', $snapshot->id)->update(['child_product_id' => null]),
        $message,
    );
    expectP4A1TriggerViolation(
        fn () => $snapshot->fresh()->update(['child_product_id' => null]),
        $message,
    );

    // Swapping the component for another product is never allowed.
    expectP4A1TriggerViolation(
        fn () => DB::table('order_item_bundle_components')->where('id', $snapshot->id)->update(['child_product_id' => $replacement->id]),
        $message,
    );

    expect(DB::table('order_item_bundle_components')->where('id', $snapshot->id)->value('child_product_id'))->toBe($component->id);

    // The real FK action (physically deleting the product) is allowed and preserves
    // the purchase evidence: only child_product_id becomes NULL.
    DB::table('products')->where('id', $component->id)->delete();

    $after = DB::table('order_item_bundle_components')->where('id', $snapshot->id)->first();

    expect($after->child_product_id)->toBeNull()
        ->and($after->child_product_name_snapshot)->toBe($component->name)
        ->and($after->child_product_slug_snapshot)->toBe($component->slug)
        ->and($after->order_item_id)->toBe($purchase['item']->id)
        ->and($after->created_at)->toBe(DB::table('order_item_bundle_components')->where('id', $snapshot->id)->value('created_at'))
        ->and(DB::table('order_items')->where('id', $purchase['item']->id)->exists())->toBeTrue();
});

it('keeps the purchase snapshot immune to later pivot changes', function () {
    $purchase = createP4A1BundlePurchase(2);
    [$a, $b] = $purchase['components'];

    snapshotP4A1($purchase['item'], $a);
    snapshotP4A1($purchase['item'], $b);

    // C is added to the bundle AFTER the purchase.
    $c = Product::factory()->create();
    $purchase['bundle']->childProducts()->attach($c->getKey(), ['position' => 5]);

    $snapshotIds = fn (): array => DB::table('order_item_bundle_components')
        ->where('order_item_id', $purchase['item']->id)
        ->orderBy('child_product_id')
        ->pluck('child_product_id')
        ->all();

    $expected = collect([$a->id, $b->id])->sort()->values()->all();
    expect($snapshotIds())->toBe($expected);

    // B is removed from the bundle AFTER the purchase: the snapshot still proves it
    // was bought, so a late re-issue can still recognise B.
    $purchase['bundle']->childProducts()->detach($b->getKey());

    expect($snapshotIds())->toBe($expected)
        ->and(DB::table('order_item_bundle_components')->where('order_item_id', $purchase['item']->id)->where('child_product_id', $b->id)->exists())->toBeTrue();

    // A late snapshot of the now-detached B is refused: the pivot no longer holds it.
    expectP4A1TriggerViolation(
        fn () => OrderItemBundleComponent::factory()->forOrderItem($purchase['item'])->forComponent($b)->create(['id' => null]),
        'order_item_bundle_components component must belong to the purchased bundle',
    );

    // DOCUMENTED RESIDUAL RISK (D-029.3), NOT a guarantee: a privileged SQL role can
    // still insert C late, because S3 legitimately reads the pivot at copy time and C
    // is in it now. PostgreSQL does NOT prevent this; permissions, the absence of any
    // mutation API and this test suite are what cover it. No API allows it.
    $lateC = snapshotP4A1($purchase['item'], $c);
    expect($lateC->exists)->toBeTrue();
});

it('lets the database accept a bundle order item with no snapshot at all', function () {
    $purchase = createP4A1BundlePurchase();

    // Deliberate (D-029.3): NO minimum-cardinality constraint exists. An empty
    // snapshot commits cleanly. Refusing an empty bundle is the future OrderService's
    // job (before creating the order_item), and P4-A2 stays fail-closed if a copy is
    // ever missed. This is an APPLICATION guarantee, never a PostgreSQL invariant.
    expect($purchase['item']->bundleComponents()->count())->toBe(0)
        ->and(DB::table('order_items')->where('id', $purchase['item']->id)->exists())->toBeTrue();

    $emptyBundlePurchase = DB::transaction(function (): OrderItem {
        $bundle = Product::factory()->bundle()->create();
        $order = Order::factory()->create();

        return OrderItem::factory()->forOrder($order)->forProduct($bundle)->create();
    });

    expect($emptyBundlePurchase->bundleComponents()->count())->toBe(0);

    $cardinalityGuards = DB::table('pg_constraint')
        ->whereRaw("conrelid = 'order_item_bundle_components'::regclass")
        ->where('contype', 't')
        ->count();

    expect($cardinalityGuards)->toBe(0);
});

it('adds no side effects to P4-A0, the pivot, order items or future gates', function () {
    $purchase = createP4A1BundlePurchase();
    $component = $purchase['components'][0];
    $pivotBefore = DB::table('product_bundles')->orderBy('bundle_id')->orderBy('child_product_id')->get();
    $itemBefore = DB::table('order_items')->where('id', $purchase['item']->id)->first();

    snapshotP4A1($purchase['item'], $component);

    expect(DB::table('product_bundles')->orderBy('bundle_id')->orderBy('child_product_id')->get())->toEqual($pivotBefore)
        ->and(DB::table('order_items')->where('id', $purchase['item']->id)->first())->toEqual($itemBefore);

    // P4-A0 (G0) untouched.
    expect(DB::table('pg_proc')->where('proname', 'enforce_product_file_content_immutability')->count())->toBe(1)
        ->and(DB::table('pg_trigger')->where('tgname', 'product_files_enforce_content_immutability_trigger')->count())->toBe(1);

    foreach (['licenses'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Unexpected future table exists: {$table}");
    }
});

it('serialises a concurrent bundle change against the future checkout orchestration', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4a1_concurrency_'.strtolower(Str::random(10)));
    $connection = config('database.connections.pgsql_migration');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName());

    $checkout = null;
    $admin = null;

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000009_create_order_item_bundle_components_table.php');

        $seed = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $seed->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES ('conc-bundle', 'Concurrent bundle', 'bundle', 'published', now(), now())");
        $seed->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES ('conc-a', 'Component A', 'ebook', 'published', now(), now())");
        $seed->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES ('conc-c', 'Component C', 'ebook', 'published', now(), now())");
        $seed->exec("INSERT INTO product_bundles (bundle_id, child_product_id, position) SELECT b.id, a.id, 0 FROM products b, products a WHERE b.slug='conc-bundle' AND a.slug='conc-a'");
        // The order and its item must land in one transaction: the P3B deferred
        // trigger validates the complete commercial snapshot at COMMIT.
        $seed->beginTransaction();
        $seed->exec("INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email, subtotal_minor, discount_minor, tax_minor, total_minor, currency, status, placed_at, expires_at, created_at, updated_at) VALUES (gen_random_uuid(), 'DGT-2026-CNC0000001', '".hash('sha256', 'conc')."', 'conc@example.test', 10000, 0, 0, 10000, 'XOF', 'pending', now(), now() + interval '30 minutes', now(), now())");
        $seed->exec("INSERT INTO order_items (order_id, product_id, product_name_snapshot, product_slug_snapshot, product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor, line_total_minor, currency, created_at, updated_at) SELECT o.id, b.id, 'Concurrent bundle', 'conc-bundle', 'bundle', 10000, 1, 10000, 0, 10000, 'XOF', now(), now() FROM orders o, products b WHERE o.order_number='DGT-2026-CNC0000001' AND b.slug='conc-bundle'");
        $seed->commit();
        $seed = null;

        $checkout = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Transaction A models the future OrderService: lock the bundle product row,
        // then copy the whole composition with ONE INSERT ... SELECT.
        $checkout->beginTransaction();
        $checkout->exec("SELECT id FROM products WHERE slug = 'conc-bundle' FOR UPDATE");

        // Transaction B is a correctly orchestrated admin change taking the same lock:
        // it must wait. (PostgreSQL does not force every admin path to take this lock —
        // that discipline belongs to the future service layer, not to the database.)
        $admin->exec("SET lock_timeout = '1000ms'");
        $admin->beginTransaction();

        $blocked = null;

        try {
            $admin->exec("SELECT id FROM products WHERE slug = 'conc-bundle' FOR UPDATE");
        } catch (PDOException $pdoException) {
            $blocked = $pdoException;
        }

        expect($blocked)->not->toBeNull()
            ->and($blocked->getCode())->toBe('55P03');

        $admin->rollBack();

        $checkout->exec(<<<'SQL'
            INSERT INTO order_item_bundle_components (order_item_id, child_product_id, child_product_name_snapshot, child_product_slug_snapshot, created_at)
            SELECT oi.id, pb.child_product_id, p.name, p.slug, now()
            FROM order_items oi
            JOIN product_bundles pb ON pb.bundle_id = oi.product_id
            JOIN products p ON p.id = pb.child_product_id
            WHERE oi.product_slug_snapshot = 'conc-bundle'
        SQL);
        $checkout->commit();

        $copied = $checkout->query('SELECT child_product_slug_snapshot FROM order_item_bundle_components ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        expect($copied)->toBe(['conc-a']);

        // Two simultaneous copies of the same snapshot: only one survives.
        $checkout->beginTransaction();
        $checkout->exec(<<<'SQL'
            INSERT INTO order_item_bundle_components (order_item_id, child_product_id, child_product_name_snapshot, child_product_slug_snapshot, created_at)
            SELECT oi.id, p.id, p.name, p.slug, now()
            FROM order_items oi, products p
            WHERE oi.product_slug_snapshot = 'conc-bundle' AND p.slug = 'conc-a'
            ON CONFLICT DO NOTHING
        SQL);
        $checkout->commit();

        expect((int) $checkout->query('SELECT COUNT(*) FROM order_item_bundle_components')->fetchColumn())->toBe(1);

        $duplicate = null;

        try {
            $admin->exec(<<<'SQL'
                INSERT INTO order_item_bundle_components (order_item_id, child_product_id, child_product_name_snapshot, child_product_slug_snapshot, created_at)
                SELECT oi.id, p.id, p.name, p.slug, now()
                FROM order_items oi, products p
                WHERE oi.product_slug_snapshot = 'conc-bundle' AND p.slug = 'conc-a'
            SQL);
        } catch (PDOException $pdoException) {
            $duplicate = $pdoException;
        }

        expect($duplicate)->not->toBeNull()
            ->and($duplicate->getCode())->toBe('23505')
            ->and($duplicate->getMessage())->toContain('oibc_order_item_child_unique');
    } finally {
        $checkout = null;
        $admin = null;
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

it('rolls back only the P4-A1 gate while preserving P4-A0 and every earlier phase', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4a1_rollback_'.strtolower(Str::random(10)));

    $boundary = '2026_07_14_000009_create_order_item_bundle_components_table.php';
    $functions = ['prevent_order_item_bundle_components_delete', 'enforce_order_item_bundle_component_immutability', 'validate_order_item_bundle_component'];
    $triggers = ['order_item_bundle_components_prevent_delete_trigger', 'order_item_bundle_components_enforce_immutability_trigger', 'order_item_bundle_components_validate_insert_trigger'];

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($boundary);

        expect(end($applied))->toBe('2026_07_14_000009_create_order_item_bundle_components_table')
            ->and($applied)->toContain('2026_07_14_000008_harden_product_files_content_immutability')
            ->and($harness->hasTable('order_item_bundle_components'))->toBeTrue()
            ->and($harness->countFunctions($functions))->toBe(3)
            ->and($harness->countTriggers($triggers))->toBe(3)
            ->and($harness->countFunctions(['enforce_product_file_content_immutability']))->toBe(1);

        // Seed a real snapshot so the rollback is proven against actual data.
        $connection = config('database.connections.pgsql_migration');
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName()),
            $connection['username'],
            $connection['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES ('rb-bundle', 'Rollback bundle', 'bundle', 'published', now(), now())");
        $pdo->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES ('rb-child', 'Rollback child', 'ebook', 'published', now(), now())");
        $pdo->exec("INSERT INTO product_bundles (bundle_id, child_product_id, position) SELECT b.id, c.id, 0 FROM products b, products c WHERE b.slug='rb-bundle' AND c.slug='rb-child'");
        // One transaction: the P3B deferred trigger validates the order at COMMIT.
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email, subtotal_minor, discount_minor, tax_minor, total_minor, currency, status, placed_at, expires_at, created_at, updated_at) VALUES (gen_random_uuid(), 'DGT-2026-RB00000001', '".hash('sha256', 'rb')."', 'rb@example.test', 10000, 0, 0, 10000, 'XOF', 'pending', now(), now() + interval '30 minutes', now(), now())");
        $pdo->exec("INSERT INTO order_items (order_id, product_id, product_name_snapshot, product_slug_snapshot, product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor, line_total_minor, currency, created_at, updated_at) SELECT o.id, b.id, 'Rollback bundle', 'rb-bundle', 'bundle', 10000, 1, 10000, 0, 10000, 'XOF', now(), now() FROM orders o, products b WHERE o.order_number='DGT-2026-RB00000001' AND b.slug='rb-bundle'");
        $pdo->commit();
        $pdo->exec("INSERT INTO order_item_bundle_components (order_item_id, child_product_id, child_product_name_snapshot, child_product_slug_snapshot, created_at) SELECT oi.id, c.id, 'Rollback child', 'rb-child', now() FROM order_items oi, products c WHERE oi.product_slug_snapshot='rb-bundle' AND c.slug='rb-child'");

        expect((int) $pdo->query('SELECT COUNT(*) FROM order_item_bundle_components')->fetchColumn())->toBe(1);

        $downed = $harness->rollbackExactMigrations([$boundary]);

        expect($downed)->toBe(['2026_07_14_000009_create_order_item_bundle_components_table'])
            ->and($harness->hasTable('order_item_bundle_components'))->toBeFalse()
            ->and($harness->countFunctions($functions))->toBe(0)
            ->and($harness->countTriggers($triggers))->toBe(0);

        // P4-A0 and every earlier phase are preserved.
        expect($harness->countFunctions(['enforce_product_file_content_immutability']))->toBe(1)
            ->and($harness->countTriggers(['product_files_enforce_content_immutability_trigger']))->toBe(1)
            ->and($harness->hasTable('products'))->toBeTrue()
            ->and($harness->hasTable('product_files'))->toBeTrue()
            ->and($harness->hasTable('product_bundles'))->toBeTrue()
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasTable('order_items'))->toBeTrue()
            ->and($harness->hasTable('refunds'))->toBeTrue()
            ->and($harness->hasConstraint('orders_coupon_snapshot_consistency_check'))->toBeTrue()
            ->and((int) $pdo->query('SELECT COUNT(*) FROM product_bundles')->fetchColumn())->toBe(1)
            ->and((int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn())->toBe(1);

        $remaining = $harness->ranMigrations();
        expect(end($remaining))->toBe('2026_07_14_000008_harden_product_files_content_immutability')
            ->and($remaining)->not->toContain('2026_07_14_000009_create_order_item_bundle_components_table');

        foreach (['download_grants', 'download_logs'] as $table) {
            expect($harness->hasTable($table))->toBeFalse();
        }

        $pdo = null;
    } finally {
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});
