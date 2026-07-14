<?php

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function forceP3BConstraints(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function expectP3BConstraintViolation(Closure $callback): void
{
    $violated = false;

    try {
        DB::transaction(function () use ($callback): void {
            $callback();
            forceP3BConstraints();
        });
    } catch (QueryException) {
        $violated = true;
    } finally {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    expect($violated)->toBeTrue();
}

/**
 * @return array{order: Order, item: OrderItem, product: Product}
 */
function createP3BOrder(
    array $orderAttributes = [],
    array $itemAttributes = [],
    ?Product $product = null,
): array {
    return DB::transaction(function () use ($orderAttributes, $itemAttributes, $product): array {
        $product ??= Product::factory()->create();
        $order = Order::factory()->create($orderAttributes);

        $subtotalMinor = (int) ($orderAttributes['subtotal_minor'] ?? 10000);
        $discountMinor = (int) ($orderAttributes['discount_minor'] ?? 0);
        $currency = (string) ($orderAttributes['currency'] ?? 'XOF');

        $item = OrderItem::factory()
            ->forOrder($order)
            ->forProduct($product)
            ->create(array_merge([
                'unit_price_minor' => $subtotalMinor,
                'quantity' => 1,
                'line_subtotal_minor' => $subtotalMinor,
                'line_discount_minor' => $discountMinor,
                'line_total_minor' => $subtotalMinor - $discountMinor,
                'currency' => $currency,
            ], $itemAttributes));

        forceP3BConstraints();

        return compact('order', 'item', 'product');
    });
}

/**
 * @return array{order: Order, item: OrderItem, product: Product, coupon: Coupon}
 */
function createP3BCouponOrder(OrderStatus $status = OrderStatus::Paid, ?Coupon $coupon = null): array
{
    $coupon ??= Coupon::factory()->percent(1000)->create();

    $result = createP3BOrder([
        'coupon_id' => $coupon->id,
        'coupon_code_snapshot' => $coupon->code,
        'coupon_discount_type_snapshot' => 'percent',
        'coupon_percent_basis_points_snapshot' => 1000,
        'coupon_fixed_amount_minor_snapshot' => null,
        'subtotal_minor' => 10000,
        'discount_minor' => 1000,
        'total_minor' => 9000,
        'status' => $status,
        'paid_at' => in_array($status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true)
            ? now()
            : null,
    ], [
        'line_discount_minor' => 1000,
        'line_total_minor' => 9000,
    ]);

    return [...$result, 'coupon' => $coupon];
}

it('runs P3B schema tests against PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('creates exactly the three P3B tables with PostgreSQL-native structural types', function () {
    foreach (['orders', 'order_items', 'coupon_redemptions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing P3B table: {$table}");
    }

    expect(Schema::hasColumns('orders', [
        'id',
        'public_id',
        'order_number',
        'cart_id',
        'checkout_idempotency_hash',
        'user_id',
        'visitor_id',
        'customer_email',
        'customer_name_snapshot',
        'billing_country_code',
        'coupon_id',
        'coupon_code_snapshot',
        'coupon_discount_type_snapshot',
        'coupon_percent_basis_points_snapshot',
        'coupon_fixed_amount_minor_snapshot',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'total_minor',
        'currency',
        'status',
        'placed_at',
        'expires_at',
        'paid_at',
        'cancelled_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'referrer_host',
        'ip_hash',
        'created_at',
        'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('order_items', [
            'id',
            'order_id',
            'product_id',
            'product_name_snapshot',
            'product_slug_snapshot',
            'product_type_snapshot',
            'unit_price_minor',
            'quantity',
            'line_subtotal_minor',
            'line_discount_minor',
            'line_total_minor',
            'currency',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('coupon_redemptions', [
            'id',
            'coupon_id',
            'order_id',
            'customer_key_version',
            'customer_key_hash',
            'coupon_code_snapshot',
            'discount_type_snapshot',
            'discount_minor',
            'currency',
            'redeemed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('coupon_redemptions', 'customer_email'))->toBeFalse()
        ->and(Schema::hasColumn('orders', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('order_items', 'deleted_at'))->toBeFalse();

    $columns = DB::table('information_schema.columns')
        ->select('table_name', 'column_name', 'data_type', 'udt_name', 'character_maximum_length')
        ->where('table_schema', 'public')
        ->whereIn('table_name', ['orders', 'order_items', 'coupon_redemptions'])
        ->get()
        ->keyBy(fn (object $column): string => "{$column->table_name}.{$column->column_name}");

    expect($columns['orders.public_id']->data_type)->toBe('uuid')
        ->and($columns['orders.customer_email']->udt_name)->toBe('citext')
        ->and($columns['orders.order_number']->data_type)->toBe('character varying')
        ->and($columns['orders.order_number']->character_maximum_length)->toBe(19)
        ->and($columns['orders.checkout_idempotency_hash']->character_maximum_length)->toBe(64)
        ->and($columns['orders.ip_hash']->character_maximum_length)->toBe(64);

    foreach (['orders.currency', 'order_items.currency', 'coupon_redemptions.currency'] as $currencyColumn) {
        expect($columns[$currencyColumn]->data_type)->toBe('character varying')
            ->and($columns[$currencyColumn]->udt_name)->toBe('varchar')
            ->and($columns[$currencyColumn]->character_maximum_length)->toBe(3);
    }

    $moneyColumns = [
        'orders.coupon_fixed_amount_minor_snapshot',
        'orders.subtotal_minor',
        'orders.discount_minor',
        'orders.tax_minor',
        'orders.total_minor',
        'order_items.unit_price_minor',
        'order_items.line_subtotal_minor',
        'order_items.line_discount_minor',
        'order_items.line_total_minor',
        'coupon_redemptions.discount_minor',
    ];

    foreach ($moneyColumns as $moneyColumn) {
        expect($columns[$moneyColumn]->data_type)->toBe('bigint', "{$moneyColumn} must be BIGINT");
    }

    $forbiddenMoneyTypes = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->whereIn('table_name', ['orders', 'order_items', 'coupon_redemptions'])
        ->whereIn('data_type', ['numeric', 'decimal', 'real', 'double precision'])
        ->where('column_name', 'like', '%\_minor')
        ->count();

    expect($forbiddenMoneyTypes)->toBe(0);
});

it('creates the required indexes, FK actions, functions, and deferred constraint triggers', function () {
    $indexDefinitions = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->whereIn('tablename', ['orders', 'order_items', 'coupon_redemptions'])
        ->pluck('indexdef', 'indexname');

    foreach ([
        'orders_status_expires_at_index',
        'orders_status_placed_at_index',
        'orders_user_id_placed_at_index',
        'orders_visitor_id_placed_at_index',
        'orders_customer_email_placed_at_index',
        'orders_coupon_id_placed_at_index',
        'order_items_order_id_index',
        'order_items_product_id_index',
        'coupon_redemptions_coupon_customer_index',
        'coupon_redemptions_coupon_redeemed_at_index',
        'coupon_redemptions_redeemed_at_index',
    ] as $indexName) {
        expect($indexDefinitions)->toHaveKey($indexName);
    }

    expect($indexDefinitions['order_items_order_id_product_id_unique'])
        ->toContain('UNIQUE')
        ->toContain('WHERE (product_id IS NOT NULL)');

    $foreignKeys = DB::table('pg_constraint')
        ->select('conname', 'confdeltype')
        ->where('contype', 'f')
        ->whereIn('conname', [
            'orders_cart_id_foreign',
            'orders_user_id_foreign',
            'orders_visitor_id_foreign',
            'orders_coupon_id_foreign',
            'order_items_order_id_foreign',
            'order_items_product_id_foreign',
            'coupon_redemptions_coupon_id_foreign',
            'coupon_redemptions_order_id_foreign',
        ])
        ->get()
        ->keyBy('conname');

    expect($foreignKeys)->toHaveCount(8);

    foreach ([
        'orders_cart_id_foreign',
        'orders_user_id_foreign',
        'orders_visitor_id_foreign',
        'orders_coupon_id_foreign',
        'order_items_product_id_foreign',
        'coupon_redemptions_coupon_id_foreign',
    ] as $setNullForeignKey) {
        expect($foreignKeys[$setNullForeignKey]->confdeltype)->toBe('n');
    }

    foreach (['order_items_order_id_foreign', 'coupon_redemptions_order_id_foreign'] as $restrictForeignKey) {
        expect($foreignKeys[$restrictForeignKey]->confdeltype)->toBe('r');
    }

    $functionNames = DB::table('pg_proc')
        ->whereIn('proname', [
            'prevent_orders_delete',
            'enforce_orders_immutability',
            'prevent_order_items_delete',
            'enforce_order_items_immutability',
            'validate_order_items_consistency',
            'validate_coupon_redemption_consistency',
        ])
        ->pluck('proname');

    expect($functionNames)->toHaveCount(6);

    $deferredTriggerNames = [
        'orders_validate_items_consistency_trigger',
        'order_items_validate_order_consistency_trigger',
        'coupon_redemptions_validate_order_consistency_trigger',
        'orders_validate_redemption_consistency_trigger',
    ];

    $deferredTriggers = DB::table('pg_trigger')
        ->select('tgname', 'tgdeferrable', 'tginitdeferred')
        ->whereIn('tgname', $deferredTriggerNames)
        ->get()
        ->keyBy('tgname');

    expect($deferredTriggers)->toHaveCount(4);

    foreach ($deferredTriggerNames as $triggerName) {
        expect((bool) $deferredTriggers[$triggerName]->tgdeferrable)->toBeTrue()
            ->and((bool) $deferredTriggers[$triggerName]->tginitdeferred)->toBeTrue();
    }

    $immediateTriggers = DB::table('pg_trigger')
        ->whereIn('tgname', [
            'orders_prevent_delete_trigger',
            'orders_enforce_immutability_trigger',
            'order_items_prevent_delete_trigger',
            'order_items_enforce_immutability_trigger',
        ])
        ->count();

    expect($immediateTriggers)->toBe(4);
});

it('supports guest orders while enforcing opaque identities, formats, and uniqueness', function () {
    ['order' => $order] = createP3BOrder();

    expect(Str::isUuid($order->public_id))->toBeTrue()
        ->and($order->order_number)->toMatch('/^DGT-[0-9]{4}-[0-9A-HJKMNP-TV-Z]{10}$/')
        ->and($order->getRawOriginal('checkout_idempotency_hash'))->toMatch('/^[0-9a-f]{64}$/')
        ->and($order->user_id)->toBeNull()
        ->and($order->visitor_id)->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->toArray())->not->toHaveKeys(['checkout_idempotency_hash', 'ip_hash']);

    expectP3BConstraintViolation(fn () => Order::factory()->create(['public_id' => $order->public_id]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['order_number' => $order->order_number]));
    expectP3BConstraintViolation(fn () => Order::factory()->create([
        'checkout_idempotency_hash' => $order->getRawOriginal('checkout_idempotency_hash'),
    ]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['order_number' => 'DGT-2026-0000000000']));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['checkout_idempotency_hash' => str_repeat('A', 64)]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['checkout_idempotency_hash' => str_repeat('a', 63)]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['ip_hash' => str_repeat('g', 64)]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['customer_email' => '   ']));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['billing_country_code' => 'ci']));

    $cart = Cart::factory()->create();
    createP3BOrder(['cart_id' => $cart->id]);
    expectP3BConstraintViolation(fn () => Order::factory()->create(['cart_id' => $cart->id]));
});

it('enforces order money, currency, dates, status, and coupon snapshot branches', function () {
    $percentCoupon = Coupon::factory()->percent(1500)->create();
    $fixedCoupon = Coupon::factory()->fixed()->create();
    ['order' => $validOrder] = createP3BOrder();

    createP3BOrder([
        'coupon_id' => $percentCoupon->id,
        'coupon_code_snapshot' => $percentCoupon->code,
        'coupon_discount_type_snapshot' => 'percent',
        'coupon_percent_basis_points_snapshot' => 1500,
        'coupon_fixed_amount_minor_snapshot' => null,
        'discount_minor' => 1500,
        'total_minor' => 8500,
    ], [
        'line_discount_minor' => 1500,
        'line_total_minor' => 8500,
    ]);

    createP3BOrder([
        'coupon_id' => $fixedCoupon->id,
        'coupon_code_snapshot' => $fixedCoupon->code,
        'coupon_discount_type_snapshot' => 'fixed',
        'coupon_percent_basis_points_snapshot' => null,
        'coupon_fixed_amount_minor_snapshot' => 2000,
        'discount_minor' => 2000,
        'total_minor' => 8000,
    ], [
        'line_discount_minor' => 2000,
        'line_total_minor' => 8000,
    ]);

    expectP3BConstraintViolation(fn () => Order::factory()->create(['subtotal_minor' => -1]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['discount_minor' => 10001, 'total_minor' => 0]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['total_minor' => 9999]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['currency' => 'xof']));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['currency' => 'XO']));
    expectP3BConstraintViolation(fn () => DB::table('orders')->where('id', $validOrder->id)->update([
        'status' => 'failed',
    ]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['expires_at' => now()->subMinute()]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['paid_at' => now()->subDay()]));
    expectP3BConstraintViolation(fn () => Order::factory()->create(['cancelled_at' => now()->subDay()]));
    expectP3BConstraintViolation(fn () => Order::factory()->create([
        'discount_minor' => 1,
        'total_minor' => 9999,
    ]));
    expectP3BConstraintViolation(fn () => Order::factory()->create([
        'coupon_code_snapshot' => 'ZERO-PERCENT',
        'coupon_discount_type_snapshot' => 'percent',
        'coupon_percent_basis_points_snapshot' => 1000,
        'discount_minor' => 0,
    ]));
    expectP3BConstraintViolation(fn () => Order::factory()->create([
        'coupon_code_snapshot' => 'ZERO-FIXED',
        'coupon_discount_type_snapshot' => 'fixed',
        'coupon_fixed_amount_minor_snapshot' => 0,
        'discount_minor' => 1,
        'total_minor' => 9999,
    ]));
});

it('maps P1, P2, and P3A relations without exposing sensitive hashes', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create();
    $cart = Cart::factory()->create();
    $coupon = Coupon::factory()->percent(1000)->create();
    $product = Product::factory()->create();

    ['order' => $order, 'item' => $item] = createP3BOrder([
        'user_id' => $user->id,
        'visitor_id' => $visitor->id,
        'cart_id' => $cart->id,
        'coupon_id' => $coupon->id,
        'coupon_code_snapshot' => $coupon->code,
        'coupon_discount_type_snapshot' => 'percent',
        'coupon_percent_basis_points_snapshot' => 1000,
        'discount_minor' => 1000,
        'total_minor' => 9000,
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
    ], [
        'line_discount_minor' => 1000,
        'line_total_minor' => 9000,
    ], $product);

    $redemption = DB::transaction(function () use ($coupon, $order): CouponRedemption {
        $redemption = CouponRedemption::factory()->create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'coupon_code_snapshot' => $order->coupon_code_snapshot,
            'discount_type_snapshot' => $order->coupon_discount_type_snapshot,
            'discount_minor' => $order->discount_minor,
            'currency' => $order->currency,
        ]);

        forceP3BConstraints();

        return $redemption;
    });

    expect($order->user->is($user))->toBeTrue()
        ->and($user->orders->first()->is($order))->toBeTrue()
        ->and($order->visitor->is($visitor))->toBeTrue()
        ->and($visitor->orders->first()->is($order))->toBeTrue()
        ->and($order->cart->is($cart))->toBeTrue()
        ->and($cart->order->is($order))->toBeTrue()
        ->and($order->coupon->is($coupon))->toBeTrue()
        ->and($coupon->orders->first()->is($order))->toBeTrue()
        ->and($order->items->first()->is($item))->toBeTrue()
        ->and($item->order->is($order))->toBeTrue()
        ->and($item->product->is($product))->toBeTrue()
        ->and($product->orderItems->first()->is($item))->toBeTrue()
        ->and($order->couponRedemption->is($redemption))->toBeTrue()
        ->and($redemption->order->is($order))->toBeTrue()
        ->and($redemption->coupon->is($coupon))->toBeTrue()
        ->and($coupon->redemptions->first()->is($redemption))->toBeTrue()
        ->and($redemption->toArray())->not->toHaveKey('customer_key_hash');
});

it('provides coherent order, item, and redemption factory states without payment logic', function () {
    $percentCoupon = Coupon::factory()->percent(1200)->create();
    $fixedCoupon = Coupon::factory()->fixed()->create();
    $user = User::factory()->create();

    $guest = Order::factory()->guest()->make();
    $account = Order::factory()->forUser($user)->make();
    $review = Order::factory()->paymentReview()->make();
    $paid = Order::factory()->paid()->make();
    $expired = Order::factory()->expired()->make();
    $cancelled = Order::factory()->cancelled()->make();
    $percent = Order::factory()->withPercentCoupon($percentCoupon, 1200, 1200)->make();
    $fixed = Order::factory()->withFixedCoupon($fixedCoupon, 1500, 1500)->make();
    $item = OrderItem::factory()->priced(2500, 2, 500)->make(['order_id' => 1, 'product_id' => null]);
    $redemption = CouponRedemption::factory()->make(['order_id' => 1]);

    expect($guest->user_id)->toBeNull()
        ->and($account->user_id)->toBe($user->id)
        ->and($review->status)->toBe(OrderStatus::PaymentReview)
        ->and($paid->status)->toBe(OrderStatus::Paid)
        ->and($paid->paid_at)->not->toBeNull()
        ->and($expired->status)->toBe(OrderStatus::Expired)
        ->and($expired->expires_at->isPast())->toBeTrue()
        ->and($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($percent->coupon_discount_type_snapshot)->toBe('percent')
        ->and($percent->discount_minor)->toBe(1200)
        ->and($fixed->coupon_discount_type_snapshot)->toBe('fixed')
        ->and($fixed->coupon_fixed_amount_minor_snapshot)->toBe(1500)
        ->and($item->line_subtotal_minor)->toBe(5000)
        ->and($item->line_total_minor)->toBe(4500)
        ->and($redemption->customer_key_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('preserves orders while provenance references are nulled by their foreign keys', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create();
    $cart = Cart::factory()->create();
    $coupon = Coupon::factory()->percent(1000)->create();

    ['order' => $order, 'item' => $item] = createP3BOrder([
        'user_id' => $user->id,
        'visitor_id' => $visitor->id,
        'cart_id' => $cart->id,
        'coupon_id' => $coupon->id,
        'coupon_code_snapshot' => $coupon->code,
        'coupon_discount_type_snapshot' => 'percent',
        'coupon_percent_basis_points_snapshot' => 1000,
        'discount_minor' => 1000,
        'total_minor' => 9000,
    ], [
        'line_discount_minor' => 1000,
        'line_total_minor' => 9000,
    ]);

    $user->forceDelete();
    $visitor->delete();
    $cart->delete();
    $coupon->delete();
    forceP3BConstraints();

    $order->refresh();

    expect($order->user_id)->toBeNull()
        ->and($order->visitor_id)->toBeNull()
        ->and($order->cart_id)->toBeNull()
        ->and($order->coupon_id)->toBeNull()
        ->and($order->coupon_code_snapshot)->not->toBeNull()
        ->and(DB::table('order_items')->where('id', $item->id)->exists())->toBeTrue();
});

it('allows lifecycle updates and controlled nullification but rejects commercial mutations', function () {
    $user = User::factory()->create();
    ['order' => $order] = createP3BOrder(['user_id' => $user->id]);

    $order->update(['status' => OrderStatus::PaymentReview]);
    forceP3BConstraints();

    expect($order->refresh()->status)->toBe(OrderStatus::PaymentReview);

    DB::table('orders')->where('id', $order->id)->update(['user_id' => null]);
    forceP3BConstraints();
    expect($order->refresh()->user_id)->toBeNull();

    $replacement = User::factory()->create();

    expectP3BConstraintViolation(fn () => DB::table('orders')->where('id', $order->id)->update([
        'user_id' => $replacement->id,
    ]));
    expectP3BConstraintViolation(fn () => DB::table('orders')->where('id', $order->id)->update([
        'subtotal_minor' => 11000,
        'total_minor' => 11000,
    ]));
    expectP3BConstraintViolation(fn () => DB::table('orders')->where('id', $order->id)->update([
        'customer_email' => 'changed@example.test',
    ]));
    expectP3BConstraintViolation(fn () => DB::table('orders')->where('id', $order->id)->update([
        'expires_at' => now()->addHour(),
    ]));
});

it('rejects physical deletion of orders even before any item exists', function () {
    expectP3BConstraintViolation(function (): void {
        $order = Order::factory()->create();
        DB::table('orders')->where('id', $order->id)->delete();
    });

    ['order' => $order] = createP3BOrder();
    expectP3BConstraintViolation(fn () => $order->delete());
});

it('keeps order items immutable while allowing only FK-driven product nullification', function () {
    ['item' => $item, 'product' => $product] = createP3BOrder();
    $snapshot = $item->only([
        'product_name_snapshot',
        'product_slug_snapshot',
        'product_type_snapshot',
        'unit_price_minor',
        'quantity',
        'line_subtotal_minor',
        'line_discount_minor',
        'line_total_minor',
        'currency',
    ]);
    $updatedAt = DB::table('order_items')->where('id', $item->id)->value('updated_at');

    expectP3BConstraintViolation(fn () => $item->delete());
    expectP3BConstraintViolation(fn () => DB::table('order_items')->where('id', $item->id)->update([
        'product_name_snapshot' => 'Changed snapshot',
    ]));
    expectP3BConstraintViolation(fn () => DB::table('order_items')->where('id', $item->id)->update([
        'unit_price_minor' => 9000,
        'line_subtotal_minor' => 9000,
        'line_total_minor' => 9000,
    ]));
    expectP3BConstraintViolation(fn () => DB::table('order_items')->where('id', $item->id)->update([
        'updated_at' => now()->addMinute(),
    ]));

    $product->forceDelete();
    forceP3BConstraints();
    $item->refresh();

    expect($item->product_id)->toBeNull()
        ->and($item->only(array_keys($snapshot)))->toBe($snapshot)
        ->and(DB::table('order_items')->where('id', $item->id)->value('updated_at'))->toBe($updatedAt);

    $replacement = Product::factory()->create();
    expectP3BConstraintViolation(fn () => DB::table('order_items')->where('id', $item->id)->update([
        'product_id' => $replacement->id,
    ]));
});

it('enforces one line per live product while retaining multiple historical null product lines', function () {
    $firstProduct = Product::factory()->create();
    $secondProduct = Product::factory()->create();

    [$order, $firstItem, $secondItem] = DB::transaction(function () use ($firstProduct, $secondProduct): array {
        $order = Order::factory()->create([
            'subtotal_minor' => 20000,
            'total_minor' => 20000,
        ]);
        $firstItem = OrderItem::factory()->forOrder($order)->forProduct($firstProduct)->create();
        $secondItem = OrderItem::factory()->forOrder($order)->forProduct($secondProduct)->create();
        forceP3BConstraints();

        return [$order, $firstItem, $secondItem];
    });

    expectP3BConstraintViolation(fn () => OrderItem::factory()
        ->forOrder($order)
        ->forProduct($firstProduct)
        ->create());

    $firstProduct->forceDelete();
    $secondProduct->forceDelete();
    forceP3BConstraints();

    expect($firstItem->refresh()->product_id)->toBeNull()
        ->and($secondItem->refresh()->product_id)->toBeNull()
        ->and(DB::table('order_items')->where('order_id', $order->id)->whereNull('product_id')->count())->toBe(2);
});

it('accepts a complete transaction and rejects missing lines, currency drift, and aggregate drift', function () {
    ['order' => $order] = createP3BOrder();
    expect($order->items)->toHaveCount(1);

    expectP3BConstraintViolation(fn () => Order::factory()->create());

    expectP3BConstraintViolation(function (): void {
        $order = Order::factory()->create();
        OrderItem::factory()->forOrder($order)->create(['currency' => 'USD']);
    });

    expectP3BConstraintViolation(function (): void {
        $order = Order::factory()->create();
        OrderItem::factory()->forOrder($order)->priced(9000)->create();
    });

    expectP3BConstraintViolation(function (): void {
        $order = Order::factory()->create([
            'discount_minor' => 1000,
            'total_minor' => 9000,
            'coupon_code_snapshot' => 'ORDER-DISCOUNT',
            'coupon_discount_type_snapshot' => 'percent',
            'coupon_percent_basis_points_snapshot' => 1000,
        ]);
        OrderItem::factory()->forOrder($order)->create();
    });

    expectP3BConstraintViolation(fn () => OrderItem::factory()->make([
        'quantity' => 0,
    ])->save());
    expectP3BConstraintViolation(fn () => OrderItem::factory()->make([
        'line_subtotal_minor' => 9999,
    ])->save());
});

it('creates no automatic redemption and accepts one matching paid-order redemption', function () {
    ['order' => $order, 'coupon' => $coupon] = createP3BCouponOrder();

    expect($order->couponRedemption)->toBeNull();

    $redemption = DB::transaction(function () use ($coupon, $order): CouponRedemption {
        $redemption = CouponRedemption::factory()->create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'customer_key_version' => 1,
            'customer_key_hash' => hash('sha256', 'customer@example.test'),
            'coupon_code_snapshot' => $order->coupon_code_snapshot,
            'discount_type_snapshot' => $order->coupon_discount_type_snapshot,
            'discount_minor' => $order->discount_minor,
            'currency' => $order->currency,
        ]);
        forceP3BConstraints();

        return $redemption;
    });

    expect($redemption->order->is($order))->toBeTrue()
        ->and($redemption->coupon->is($coupon))->toBeTrue()
        ->and($redemption->customer_key_version)->toBe(1)
        ->and($redemption->getRawOriginal('customer_key_hash'))->toMatch('/^[0-9a-f]{64}$/');

    expectP3BConstraintViolation(fn () => CouponRedemption::factory()->create([
        'coupon_id' => $coupon->id,
        'order_id' => $order->id,
        'coupon_code_snapshot' => $order->coupon_code_snapshot,
        'discount_type_snapshot' => $order->coupon_discount_type_snapshot,
        'discount_minor' => $order->discount_minor,
        'currency' => $order->currency,
    ]));
});

it('rejects malformed or inconsistent coupon redemptions at the PostgreSQL layer', function () {
    ['order' => $paidOrder, 'coupon' => $coupon] = createP3BCouponOrder();

    expectP3BConstraintViolation(fn () => CouponRedemption::factory()->create([
        'order_id' => $paidOrder->id,
        'customer_key_version' => 0,
    ]));
    expectP3BConstraintViolation(fn () => CouponRedemption::factory()->create([
        'order_id' => $paidOrder->id,
        'customer_key_hash' => str_repeat('A', 64),
    ]));

    ['order' => $noCouponOrder] = createP3BOrder([
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
    ]);

    expectP3BConstraintViolation(fn () => CouponRedemption::factory()->create([
        'coupon_id' => null,
        'order_id' => $noCouponOrder->id,
        'coupon_code_snapshot' => 'NO-COUPON',
        'discount_type_snapshot' => 'percent',
        'discount_minor' => 0,
        'currency' => 'XOF',
    ]));

    foreach ([
        ['coupon_code_snapshot' => 'MISMATCH'],
        ['discount_type_snapshot' => 'fixed'],
        ['discount_minor' => 999],
        ['currency' => 'USD'],
        ['coupon_id' => Coupon::factory()->create()->id],
    ] as $mismatch) {
        expectP3BConstraintViolation(fn () => CouponRedemption::factory()->create(array_merge([
            'coupon_id' => $coupon->id,
            'order_id' => $paidOrder->id,
            'coupon_code_snapshot' => $paidOrder->coupon_code_snapshot,
            'discount_type_snapshot' => $paidOrder->coupon_discount_type_snapshot,
            'discount_minor' => $paidOrder->discount_minor,
            'currency' => $paidOrder->currency,
        ], $mismatch)));
    }

    foreach ([OrderStatus::Pending, OrderStatus::PaymentReview, OrderStatus::Cancelled, OrderStatus::Expired] as $status) {
        ['order' => $order, 'coupon' => $statusCoupon] = createP3BCouponOrder($status);

        expectP3BConstraintViolation(fn () => CouponRedemption::factory()->create([
            'coupon_id' => $statusCoupon->id,
            'order_id' => $order->id,
            'coupon_code_snapshot' => $order->coupon_code_snapshot,
            'discount_type_snapshot' => $order->coupon_discount_type_snapshot,
            'discount_minor' => $order->discount_minor,
            'currency' => $order->currency,
        ]));
    }
});

it('allows the future deferred redemption sequence and preserves snapshots after coupon deletion', function () {
    ['order' => $order, 'coupon' => $coupon] = createP3BCouponOrder(OrderStatus::Pending);

    $redemption = DB::transaction(function () use ($coupon, $order): CouponRedemption {
        $redemption = CouponRedemption::factory()->create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'coupon_code_snapshot' => $order->coupon_code_snapshot,
            'discount_type_snapshot' => $order->coupon_discount_type_snapshot,
            'discount_minor' => $order->discount_minor,
            'currency' => $order->currency,
        ]);

        $order->update([
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);

        forceP3BConstraints();

        return $redemption;
    });

    $codeSnapshot = $order->coupon_code_snapshot;
    $coupon->delete();
    forceP3BConstraints();

    expect($order->refresh()->coupon_id)->toBeNull()
        ->and($order->coupon_code_snapshot)->toBe($codeSnapshot)
        ->and($redemption->refresh()->coupon_id)->toBeNull()
        ->and($redemption->coupon_code_snapshot)->toBe($codeSnapshot);
});

it('does not create P3C, delivery, analytics, or affiliation tables', function () {
    $forbiddenTables = [
        'payments',
        'payment_webhook_events',
        'refunds',
        'download_grants',
        'download_logs',
        'events',
        'analytics_sessions',
        'campaigns',
        'daily_sales_stats',
        'customer_segments',
        'affiliate_profiles',
        'affiliate_links',
        'referrals',
    ];

    foreach ($forbiddenTables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Unexpected out-of-scope table exists: {$table}");
    }
});
