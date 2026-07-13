<?php

use App\Enums\CartStatus;
use App\Enums\CouponDiscountType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponCurrencyRule;
use App\Models\Product;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function expectP3AConstraintViolation(Closure $callback): void
{
    expect(fn () => DB::transaction($callback))->toThrow(QueryException::class);
}

it('runs P3A schema tests against PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('has exactly the six P3A tables and expected columns', function () {
    $p3aTables = [
        'coupons',
        'coupon_currency_rules',
        'coupon_products',
        'coupon_categories',
        'carts',
        'cart_items',
    ];

    foreach ($p3aTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing P3A table: {$table}");
    }

    expect(Schema::hasColumns('coupons', [
        'id',
        'code',
        'discount_type',
        'percent_basis_points',
        'max_redemptions',
        'redemptions_count',
        'max_redemptions_per_customer',
        'starts_at',
        'ends_at',
        'is_active',
        'created_at',
        'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('coupon_currency_rules', [
            'id',
            'coupon_id',
            'currency',
            'fixed_amount_minor',
            'min_order_minor',
            'max_discount_minor',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('carts', [
            'id',
            'public_id',
            'secret_hash',
            'visitor_id',
            'user_id',
            'coupon_id',
            'currency',
            'status',
            'expires_at',
            'abandoned_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('cart_items', [
            'id',
            'cart_id',
            'product_id',
            'quantity',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

    foreach (['currency', 'price_minor', 'discount_minor', 'subtotal_minor', 'total_minor', 'unit_price_minor'] as $column) {
        expect(Schema::hasColumn('cart_items', $column))->toBeFalse("Forbidden cart_items column exists: {$column}");
    }

    foreach (['currency', 'fixed_amount_minor', 'amount_minor', 'is_cumulative'] as $column) {
        expect(Schema::hasColumn('coupons', $column))->toBeFalse("Forbidden coupons column exists: {$column}");
    }

    $structuralColumns = DB::table('information_schema.columns')
        ->select('table_name', 'column_name', 'data_type', 'udt_name', 'character_maximum_length')
        ->where('table_schema', 'public')
        ->whereIn('table_name', ['coupons', 'coupon_currency_rules', 'carts'])
        ->whereIn('column_name', ['code', 'public_id', 'currency'])
        ->get()
        ->keyBy(fn (object $column): string => "{$column->table_name}.{$column->column_name}");

    expect($structuralColumns)->toHaveCount(4)
        ->and($structuralColumns['coupons.code']->udt_name)->toBe('citext')
        ->and($structuralColumns['carts.public_id']->data_type)->toBe('uuid')
        ->and($structuralColumns['carts.public_id']->udt_name)->toBe('uuid');

    foreach (['coupon_currency_rules.currency', 'carts.currency'] as $currencyColumn) {
        expect($structuralColumns[$currencyColumn]->data_type)->toBe('character varying')
            ->and($structuralColumns[$currencyColumn]->udt_name)->toBe('varchar')
            ->and($structuralColumns[$currencyColumn]->character_maximum_length)->toBe(3);
    }
});

it('does not create P3B, P3C, delivery, analytics, or affiliation tables', function () {
    $forbiddenTables = [
        'orders',
        'order_items',
        'payments',
        'payment_webhook_events',
        'refunds',
        'coupon_redemptions',
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

it('enforces case-insensitive non-blank coupon codes', function () {
    Coupon::factory()->create(['code' => 'WELCOME10']);

    expectP3AConstraintViolation(fn () => Coupon::factory()->create(['code' => 'welcome10']));
    expectP3AConstraintViolation(fn () => Coupon::factory()->create(['code' => '   ']));
});

it('enforces coupon type, percentage, limits, counters, and date coherence', function () {
    Coupon::factory()->percent(2500)->create();
    Coupon::factory()->fixed()->create();

    expectP3AConstraintViolation(fn () => DB::table('coupons')->insert([
        'code' => 'INVALID-TYPE',
        'discount_type' => 'amount',
        'percent_basis_points' => null,
        'redemptions_count' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
    expectP3AConstraintViolation(fn () => Coupon::factory()->percent(0)->create());
    expectP3AConstraintViolation(fn () => Coupon::factory()->percent(10001)->create());
    expectP3AConstraintViolation(fn () => Coupon::factory()->fixed()->create(['percent_basis_points' => 1000]));
    expectP3AConstraintViolation(fn () => Coupon::factory()->create(['max_redemptions' => 0]));
    expectP3AConstraintViolation(fn () => Coupon::factory()->create(['max_redemptions_per_customer' => 0]));
    expectP3AConstraintViolation(fn () => Coupon::factory()->create(['redemptions_count' => -1]));
    expectP3AConstraintViolation(fn () => Coupon::factory()->create([
        'starts_at' => now(),
        'ends_at' => now()->subDay(),
    ]));
});

it('supports independent multi-currency coupon rules with integer money only', function () {
    $coupon = Coupon::factory()->fixed()->create();

    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
        'fixed_amount_minor' => 5000,
        'min_order_minor' => 10000,
    ]);
    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'USD',
        'fixed_amount_minor' => 10,
        'min_order_minor' => 20,
    ]);

    expect($coupon->currencyRules)->toHaveCount(2);

    expectP3AConstraintViolation(fn () => CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
    ]));
    expectP3AConstraintViolation(fn () => CouponCurrencyRule::factory()->create(['currency' => 'xof']));
    expectP3AConstraintViolation(fn () => CouponCurrencyRule::factory()->create(['currency' => 'XO']));
    expectP3AConstraintViolation(fn () => CouponCurrencyRule::factory()->create(['currency' => 'XOFF']));
    expectP3AConstraintViolation(fn () => CouponCurrencyRule::factory()->create(['fixed_amount_minor' => -1]));
    expectP3AConstraintViolation(fn () => CouponCurrencyRule::factory()->create(['min_order_minor' => -1]));
    expectP3AConstraintViolation(fn () => CouponCurrencyRule::factory()->create(['max_discount_minor' => -1]));

    $moneyColumns = DB::table('information_schema.columns')
        ->select('column_name', 'data_type')
        ->where('table_schema', 'public')
        ->where('table_name', 'coupon_currency_rules')
        ->whereIn('column_name', ['fixed_amount_minor', 'min_order_minor', 'max_discount_minor'])
        ->get();

    expect($moneyColumns)->toHaveCount(3);

    foreach ($moneyColumns as $column) {
        expect($column->data_type)->toBe('bigint');
    }
});

it('maps coupon eligibility relations and rejects duplicate targets', function () {
    $coupon = Coupon::factory()->create();
    $globalCoupon = Coupon::factory()->create();
    $product = Product::factory()->create();
    $category = Category::factory()->create();

    $coupon->products()->attach($product->id);
    $coupon->categories()->attach($category->id);

    expect($coupon->products->first()->is($product))->toBeTrue()
        ->and($product->coupons->first()->is($coupon))->toBeTrue()
        ->and($coupon->categories->first()->is($category))->toBeTrue()
        ->and($category->coupons->first()->is($coupon))->toBeTrue()
        ->and($globalCoupon->products)->toBeEmpty()
        ->and($globalCoupon->categories)->toBeEmpty();

    expectP3AConstraintViolation(fn () => $coupon->products()->attach($product->id));
    expectP3AConstraintViolation(fn () => $coupon->categories()->attach($category->id));
});

it('has explicit reverse lookup and cart lifecycle indexes', function () {
    $indexNames = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->whereIn('tablename', ['coupon_products', 'coupon_categories', 'carts', 'cart_items'])
        ->pluck('indexname');

    expect($indexNames)
        ->toContain('coupon_products_product_id_index')
        ->toContain('coupon_categories_category_id_index')
        ->toContain('carts_status_expires_at_index')
        ->toContain('carts_user_id_index')
        ->toContain('carts_visitor_id_index')
        ->toContain('carts_coupon_id_index')
        ->toContain('cart_items_product_id_index');
});

it('stores opaque cart identifiers and only a hidden SHA-256 secret hash', function () {
    $cart = Cart::factory()->create();

    expect(Str::isUuid($cart->public_id))->toBeTrue()
        ->and($cart->getRawOriginal('secret_hash'))->toMatch('/^[0-9a-f]{64}$/')
        ->and($cart->toArray())->not->toHaveKey('secret_hash')
        ->and(Schema::hasColumn('carts', 'secret'))->toBeFalse()
        ->and(Schema::hasColumn('carts', 'secret_token'))->toBeFalse();

    expectP3AConstraintViolation(fn () => Cart::factory()->create(['public_id' => $cart->public_id]));
    expectP3AConstraintViolation(fn () => Cart::factory()->create(['secret_hash' => $cart->getRawOriginal('secret_hash')]));
    expectP3AConstraintViolation(fn () => Cart::factory()->create(['secret_hash' => str_repeat('A', 64)]));
    expectP3AConstraintViolation(fn () => Cart::factory()->create(['secret_hash' => str_repeat('a', 63)]));
    expectP3AConstraintViolation(fn () => Cart::factory()->create(['secret_hash' => str_repeat('g', 64)]));
});

it('supports guest carts, optional identity, one coupon, and constrained currency and status', function () {
    $guestCart = Cart::factory()->create();
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create();
    $coupon = Coupon::factory()->create();
    $cart = Cart::factory()->create([
        'user_id' => $user->id,
        'visitor_id' => $visitor->id,
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
    ]);

    expect($guestCart->user_id)->toBeNull()
        ->and($guestCart->visitor_id)->toBeNull()
        ->and($cart->user->is($user))->toBeTrue()
        ->and($user->carts->first()->is($cart))->toBeTrue()
        ->and($cart->visitor->is($visitor))->toBeTrue()
        ->and($visitor->carts->first()->is($cart))->toBeTrue()
        ->and($cart->coupon->is($coupon))->toBeTrue()
        ->and($coupon->carts->first()->is($cart))->toBeTrue()
        ->and($cart->status)->toBe(CartStatus::Active);

    expectP3AConstraintViolation(fn () => Cart::factory()->create(['currency' => 'xof']));
    expectP3AConstraintViolation(fn () => Cart::factory()->create(['currency' => 'XO']));
    expectP3AConstraintViolation(fn () => Cart::factory()->create(['currency' => 'XOFF']));
    expectP3AConstraintViolation(fn () => DB::table('carts')->insert([
        'public_id' => (string) Str::uuid(),
        'secret_hash' => hash('sha256', 'invalid-status'),
        'status' => 'pending',
        'expires_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]));
    expectP3AConstraintViolation(fn () => Cart::factory()->create(['expires_at' => null]));
});

it('sets cart identity and coupon references to null on physical deletion', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create();
    $coupon = Coupon::factory()->create();
    $cart = Cart::factory()->create([
        'user_id' => $user->id,
        'visitor_id' => $visitor->id,
        'coupon_id' => $coupon->id,
    ]);

    $user->forceDelete();
    $visitor->delete();
    $coupon->delete();

    expect($cart->refresh()->user_id)->toBeNull()
        ->and($cart->visitor_id)->toBeNull()
        ->and($cart->coupon_id)->toBeNull();
});

it('cascades cart deletion to its items while preserving products', function () {
    $product = Product::factory()->create();
    $cart = Cart::factory()->create();
    $item = CartItem::factory()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
    ]);

    expect(DB::table('carts')->where('id', $cart->id)->exists())->toBeTrue()
        ->and(DB::table('cart_items')->where('id', $item->id)->exists())->toBeTrue()
        ->and(DB::table('products')->where('id', $product->id)->exists())->toBeTrue();

    $cart->delete();

    expect(DB::table('carts')->where('id', $cart->id)->exists())->toBeFalse()
        ->and(DB::table('cart_items')->where('id', $item->id)->exists())->toBeFalse()
        ->and(DB::table('products')->where('id', $product->id)->exists())->toBeTrue();
});

it('cascades coupon deletion to currency rules and eligibility pivots', function () {
    $coupon = Coupon::factory()->fixed()->create();
    $currencyRule = CouponCurrencyRule::factory()->create(['coupon_id' => $coupon->id]);
    $product = Product::factory()->create();
    $category = Category::factory()->create();

    $coupon->products()->attach($product->id);
    $coupon->categories()->attach($category->id);

    expect(DB::table('coupons')->where('id', $coupon->id)->exists())->toBeTrue()
        ->and(DB::table('coupon_currency_rules')->where('id', $currencyRule->id)->exists())->toBeTrue()
        ->and(DB::table('coupon_products')->where('coupon_id', $coupon->id)->where('product_id', $product->id)->exists())->toBeTrue()
        ->and(DB::table('coupon_categories')->where('coupon_id', $coupon->id)->where('category_id', $category->id)->exists())->toBeTrue()
        ->and(DB::table('products')->where('id', $product->id)->exists())->toBeTrue()
        ->and(DB::table('categories')->where('id', $category->id)->exists())->toBeTrue();

    $coupon->delete();

    expect(DB::table('coupons')->where('id', $coupon->id)->exists())->toBeFalse()
        ->and(DB::table('coupon_currency_rules')->where('id', $currencyRule->id)->exists())->toBeFalse()
        ->and(DB::table('coupon_products')->where('coupon_id', $coupon->id)->where('product_id', $product->id)->exists())->toBeFalse()
        ->and(DB::table('coupon_categories')->where('coupon_id', $coupon->id)->where('category_id', $category->id)->exists())->toBeFalse()
        ->and(DB::table('products')->where('id', $product->id)->exists())->toBeTrue()
        ->and(DB::table('categories')->where('id', $category->id)->exists())->toBeTrue();
});

it('maps cart items, enforces quantity and uniqueness, and protects referenced products', function () {
    $cart = Cart::factory()->create();
    $product = Product::factory()->create();
    $item = CartItem::factory()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    expect($cart->items->first()->is($item))->toBeTrue()
        ->and($item->cart->is($cart))->toBeTrue()
        ->and($item->product->is($product))->toBeTrue()
        ->and($product->cartItems->first()->is($item))->toBeTrue()
        ->and($item->quantity)->toBe(2);

    expectP3AConstraintViolation(fn () => CartItem::factory()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
    ]));
    expectP3AConstraintViolation(fn () => CartItem::factory()->create(['quantity' => 0]));
    expectP3AConstraintViolation(fn () => $product->forceDelete());
});

it('provides coherent coupon and cart factory states without cross-table business logic', function () {
    $fixed = Coupon::factory()->fixed()->create();
    $inactive = Coupon::factory()->inactive()->create();
    $expiredCoupon = Coupon::factory()->expired()->create();
    $convertedCart = Cart::factory()->converted()->create();
    $abandonedCart = Cart::factory()->abandoned()->create();
    $expiredCart = Cart::factory()->expired()->create();

    expect($fixed->discount_type)->toBe(CouponDiscountType::Fixed)
        ->and($fixed->percent_basis_points)->toBeNull()
        ->and($fixed->currencyRules)->toBeEmpty()
        ->and($inactive->is_active)->toBeFalse()
        ->and($expiredCoupon->ends_at->isPast())->toBeTrue()
        ->and($convertedCart->status)->toBe(CartStatus::Converted)
        ->and($abandonedCart->status)->toBe(CartStatus::Abandoned)
        ->and($abandonedCart->abandoned_at)->not->toBeNull()
        ->and($expiredCart->status)->toBe(CartStatus::Expired)
        ->and($expiredCart->expires_at->isPast())->toBeTrue();
});
