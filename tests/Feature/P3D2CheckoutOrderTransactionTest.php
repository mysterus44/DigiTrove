<?php

declare(strict_types=1);

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponCurrencyRule;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Checkout\CheckoutException;
use App\Services\Checkout\CheckoutRefusalReason;
use App\Services\Checkout\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| P3-D2 — Checkout Order Transaction (D-031)
|--------------------------------------------------------------------------
|
| Real PostgreSQL, business queries under the restricted `digitrove_runtime`
| role. The gate creates Order + OrderItems + exhaustive bundle snapshots and
| converts the Cart in ONE transaction. It never writes payments, coupon
| redemptions, grants or logs.
*/

function p3d2Service(): OrderService
{
    return app(OrderService::class);
}

function p3d2Key(string $seed = 'a'): string
{
    return str_pad($seed, 40, $seed === '' ? 'x' : $seed);
}

function p3d2Product(int $priceMinor, string $currency = 'XOF', bool $activePrice = true): Product
{
    $product = Product::factory()->create([
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => $currency,
        'price_minor' => $priceMinor,
        'compare_at_price_minor' => null,
        'is_active' => $activePrice,
    ]);

    return $product;
}

/**
 * A published bundle priced in XOF, composed of $childCount published children.
 *
 * @return array{0: Product, 1: list<Product>}
 */
function p3d2Bundle(int $priceMinor, int $childCount = 2): array
{
    $bundle = Product::factory()->bundle()->create([
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    ProductPrice::factory()->create([
        'product_id' => $bundle->id,
        'currency' => 'XOF',
        'price_minor' => $priceMinor,
        'compare_at_price_minor' => null,
        'is_active' => true,
    ]);

    $children = [];

    for ($i = 0; $i < $childCount; $i++) {
        $child = Product::factory()->create([
            'status' => ProductStatus::Published,
            'published_at' => now()->subDay(),
        ]);
        $bundle->childProducts()->attach($child->id, ['position' => $i]);
        $children[] = $child;
    }

    return [$bundle, $children];
}

/**
 * @param  list<array{product: Product, quantity?: int}>  $lines
 */
function p3d2Cart(array $lines, ?User $user = null, ?Visitor $visitor = null, ?Coupon $coupon = null): Cart
{
    $cart = Cart::factory()->create([
        'user_id' => $user?->id,
        'visitor_id' => $visitor?->id,
        'coupon_id' => $coupon?->id,
    ]);

    foreach ($lines as $line) {
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $line['product']->id,
            'quantity' => $line['quantity'] ?? 1,
        ]);
    }

    return $cart->fresh();
}

function p3d2ExpectRefusal(Closure $callback, CheckoutRefusalReason $reason): void
{
    try {
        $callback();
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException("Expected checkout refusal [{$reason->value}] but it succeeded.");
}

function p3d2AssertNoDownstreamWrites(): void
{
    expect(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('coupon_redemptions')->count())->toBe(0)
        ->and(DB::table('download_grants')->count())->toBe(0)
        ->and(DB::table('download_logs')->count())->toBe(0);
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------

it('runs the checkout gate under the restricted runtime role', function () {
    $identity = DB::selectOne('SELECT current_user AS role_name');

    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and($identity->role_name)->toBe('digitrove_runtime');
});

// ---------------------------------------------------------------------------
// Nominal path
// ---------------------------------------------------------------------------

it('creates a pending order for an authenticated user and converts the cart', function () {
    $user = User::factory()->create();
    $product = p3d2Product(12_500);
    $cart = p3d2Cart([['product' => $product]], user: $user);

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key());

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->currency)->toBe('XOF')
        ->and($order->subtotal_minor)->toBe(12_500)
        ->and($order->discount_minor)->toBe(0)
        ->and($order->tax_minor)->toBe(0)
        ->and($order->total_minor)->toBe(12_500)
        ->and($order->cart_id)->toBe($cart->id)
        ->and($order->user_id)->toBe($user->id)
        ->and($order->visitor_id)->toBeNull()
        ->and($order->customer_email)->toBe($user->email)
        ->and($order->paid_at)->toBeNull()
        ->and($order->order_number)->toMatch('/\ADGT-\d{4}-[0-9A-HJKMNP-TV-Z]{10}\z/')
        ->and($order->checkout_idempotency_hash)->toBe(hash('sha256', p3d2Key()))
        ->and($cart->fresh()->status)->toBe(CartStatus::Converted);

    $items = $order->items()->get();

    expect($items)->toHaveCount(1)
        ->and($items[0]->product_id)->toBe($product->id)
        ->and($items[0]->product_name_snapshot)->toBe($product->name)
        ->and($items[0]->product_slug_snapshot)->toBe($product->slug)
        ->and($items[0]->product_type_snapshot)->toBe($product->type->value)
        ->and($items[0]->unit_price_minor)->toBe(12_500)
        ->and($items[0]->line_total_minor)->toBe(12_500);

    p3d2AssertNoDownstreamWrites();
});

it('creates a pending order for a guest visitor using the supplied email', function () {
    $visitor = Visitor::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(4_000), 'quantity' => 2]], visitor: $visitor);

    $order = p3d2Service()->checkout($visitor, $cart->public_id, 'XOF', p3d2Key('b'), 'guest@digitrove.test');

    expect($order->user_id)->toBeNull()
        ->and($order->visitor_id)->toBe($visitor->id)
        ->and($order->customer_email)->toBe('guest@digitrove.test')
        ->and($order->total_minor)->toBe(8_000)
        ->and($cart->fresh()->status)->toBe(CartStatus::Converted);
});

it('prices several lines and keeps every order formula exact', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([
        ['product' => p3d2Product(1_333), 'quantity' => 3],
        ['product' => p3d2Product(2_667)],
        ['product' => p3d2Product(4_001), 'quantity' => 2],
    ], user: $user);

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('c'));
    $items = $order->items()->get();

    $subtotals = $items->sum('line_subtotal_minor');
    $discounts = $items->sum('line_discount_minor');
    $totals = $items->sum('line_total_minor');

    expect($items)->toHaveCount(3)
        ->and($subtotals)->toBe($order->subtotal_minor)
        ->and($discounts)->toBe($order->discount_minor)
        ->and($totals + $order->tax_minor)->toBe($order->total_minor)
        ->and($order->total_minor)->toBe($order->subtotal_minor - $order->discount_minor + $order->tax_minor);

    foreach ($items as $item) {
        expect($item->line_subtotal_minor)->toBe($item->unit_price_minor * $item->quantity)
            ->and($item->line_total_minor)->toBe($item->line_subtotal_minor - $item->line_discount_minor);
    }
});

it('keeps a free order pending without creating any payment', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(0)]], user: $user);

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('d'));

    expect($order->total_minor)->toBe(0)
        ->and($order->status)->toBe(OrderStatus::Pending);

    p3d2AssertNoDownstreamWrites();
});

it('freezes a global coupon snapshot without consuming any quota', function () {
    $user = User::factory()->create();
    $coupon = Coupon::factory()->percent(1_500)->create();
    $cart = p3d2Cart([
        ['product' => p3d2Product(10_000)],
        ['product' => p3d2Product(5_000)],
    ], user: $user, coupon: $coupon);

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('e'));

    expect($order->discount_minor)->toBe(2_250)
        ->and($order->total_minor)->toBe(12_750)
        ->and($order->coupon_id)->toBe($coupon->id)
        ->and($order->coupon_code_snapshot)->toBe($coupon->code)
        ->and($order->coupon_discount_type_snapshot)->toBe('percent')
        ->and($order->coupon_percent_basis_points_snapshot)->toBe(1_500)
        ->and($order->coupon_fixed_amount_minor_snapshot)->toBeNull()
        ->and((int) $order->items()->sum('line_discount_minor'))->toBe(2_250)
        ->and($coupon->fresh()->redemptions_count)->toBe(0);

    p3d2AssertNoDownstreamWrites();
});

it('freezes a fixed coupon snapshot from its currency rule', function () {
    $user = User::factory()->create();
    $coupon = Coupon::factory()->fixed()->create();
    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
        'fixed_amount_minor' => 3_000,
        'min_order_minor' => 0,
        'max_discount_minor' => null,
    ]);
    $cart = p3d2Cart([['product' => p3d2Product(20_000)]], user: $user, coupon: $coupon);

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('f'));

    expect($order->discount_minor)->toBe(3_000)
        ->and($order->coupon_discount_type_snapshot)->toBe('fixed')
        ->and($order->coupon_fixed_amount_minor_snapshot)->toBe(3_000)
        ->and($order->coupon_percent_basis_points_snapshot)->toBeNull();
});

it('restricts a category scoped coupon to the eligible line', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $eligible = p3d2Product(8_000);
    $other = p3d2Product(8_000);
    $eligible->categories()->attach($category->id);

    $coupon = Coupon::factory()->percent(2_500)->create();
    $coupon->categories()->attach($category->id);

    $cart = p3d2Cart([
        ['product' => $eligible],
        ['product' => $other],
    ], user: $user, coupon: $coupon);

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('g'));
    $byProduct = $order->items()->get()->keyBy('product_id');

    expect($order->discount_minor)->toBe(2_000)
        ->and($byProduct[$eligible->id]->line_discount_minor)->toBe(2_000)
        ->and($byProduct[$other->id]->line_discount_minor)->toBe(0);
});

// ---------------------------------------------------------------------------
// Bundle snapshots
// ---------------------------------------------------------------------------

it('copies an exhaustive bundle snapshot for a purchased bundle', function () {
    $user = User::factory()->create();
    [$bundle, $children] = p3d2Bundle(30_000, 3);
    $cart = p3d2Cart([['product' => $bundle]], user: $user);

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('h'));
    $item = $order->items()->first();

    $snapshots = DB::table('order_item_bundle_components')
        ->where('order_item_id', $item->id)->orderBy('child_product_id')->get();

    expect($snapshots)->toHaveCount(3);

    foreach ($children as $index => $child) {
        expect($snapshots[$index]->child_product_id)->toBe($child->id)
            ->and($snapshots[$index]->child_product_name_snapshot)->toBe($child->name)
            ->and($snapshots[$index]->child_product_slug_snapshot)->toBe($child->slug);
    }
});

it('snapshots several bundles in the same order', function () {
    $user = User::factory()->create();
    [$first] = p3d2Bundle(10_000, 2);
    [$second] = p3d2Bundle(20_000, 4);
    $cart = p3d2Cart([['product' => $first], ['product' => $second]], user: $user);

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('i'));

    expect(DB::table('order_item_bundle_components')->count())->toBe(6);
});

it('refuses a bundle with no component instead of creating an empty snapshot', function () {
    $user = User::factory()->create();
    $bundle = Product::factory()->bundle()->create([
        'status' => ProductStatus::Published, 'published_at' => now()->subDay(),
    ]);
    ProductPrice::factory()->create([
        'product_id' => $bundle->id, 'currency' => 'XOF',
        'price_minor' => 5_000, 'compare_at_price_minor' => null, 'is_active' => true,
    ]);
    $cart = p3d2Cart([['product' => $bundle]], user: $user);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('j')),
        CheckoutRefusalReason::BundleEmpty,
    );

    expect(Order::count())->toBe(0)
        ->and($cart->fresh()->status)->toBe(CartStatus::Active);
});

it('refuses the whole checkout when a bundle component is soft deleted (D-031 Q1=C)', function () {
    $user = User::factory()->create();
    [$bundle, $children] = p3d2Bundle(30_000, 3);
    $cart = p3d2Cart([['product' => $bundle]], user: $user);

    $children[1]->delete();

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('k')),
        CheckoutRefusalReason::BundleComponentUnavailable,
    );

    // No silent filtering: nothing at all is written.
    expect(Order::count())->toBe(0)
        ->and(DB::table('order_items')->count())->toBe(0)
        ->and(DB::table('order_item_bundle_components')->count())->toBe(0)
        ->and($cart->fresh()->status)->toBe(CartStatus::Active);
});

it('refuses a bundle containing a nested bundle', function () {
    $user = User::factory()->create();
    [$bundle] = p3d2Bundle(30_000, 1);
    [$nested] = p3d2Bundle(1_000, 1);
    $bundle->childProducts()->attach($nested->id, ['position' => 9]);

    $cart = p3d2Cart([['product' => $bundle]], user: $user);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('l')),
        CheckoutRefusalReason::BundleComponentUnavailable,
    );

    expect(Order::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Ownership and cart state
// ---------------------------------------------------------------------------

it('refuses an unknown cart with the same uniform reason as a foreign cart', function () {
    $user = User::factory()->create();

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, (string) Str::uuid(), 'XOF', p3d2Key('m')),
        CheckoutRefusalReason::CartUnavailable,
    );
});

it('refuses a cart owned by another user without leaking its existence', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(1_000)]], user: $owner);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($intruder, $cart->public_id, 'XOF', p3d2Key('n')),
        CheckoutRefusalReason::CartUnavailable,
    );

    expect(Order::count())->toBe(0)
        ->and($cart->fresh()->status)->toBe(CartStatus::Active);
});

it('refuses a cart owned by another visitor', function () {
    $owner = Visitor::factory()->create();
    $intruder = Visitor::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(1_000)]], visitor: $owner);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($intruder, $cart->public_id, 'XOF', p3d2Key('o'), 'x@y.test'),
        CheckoutRefusalReason::CartUnavailable,
    );
});

it('refuses a visitor claiming a cart that already belongs to an account', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create();
    $cart = Cart::factory()->create(['user_id' => $user->id, 'visitor_id' => $visitor->id]);
    CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => p3d2Product(1_000)->id, 'quantity' => 1]);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($visitor, $cart->fresh()->public_id, 'XOF', p3d2Key('p'), 'x@y.test'),
        CheckoutRefusalReason::CartUnavailable,
    );
});

it('refuses an ownerless cart', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(1_000)]]);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('q')),
        CheckoutRefusalReason::CartUnavailable,
    );
});

it('refuses an expired cart', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(1_000)]], user: $user);
    DB::table('carts')->where('id', $cart->id)->update(['expires_at' => now()->subHour()]);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('r')),
        CheckoutRefusalReason::CartExpired,
    );
});

it('refuses a cart that is not active', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(1_000)]], user: $user);
    DB::table('carts')->where('id', $cart->id)->update(['status' => CartStatus::Abandoned->value]);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('s')),
        CheckoutRefusalReason::CartNotActive,
    );
});

it('refuses an empty cart', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([], user: $user);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('t')),
        CheckoutRefusalReason::CartEmpty,
    );
});

it('refuses a guest checkout without a usable email', function (string $email) {
    $visitor = Visitor::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(1_000)]], visitor: $visitor);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($visitor, $cart->public_id, 'XOF', p3d2Key('u'), $email),
        CheckoutRefusalReason::InvalidEmail,
    );
})->with([[''], ['   '], ['not-an-email'], ['a@'], ['@b.test']]);

it('refuses a malformed currency and a malformed idempotency key', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(1_000)]], user: $user);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'xof', p3d2Key('v')),
        CheckoutRefusalReason::InvalidCurrency,
    );

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', 'short'),
        CheckoutRefusalReason::InvalidIdempotencyKey,
    );
});

it('refuses an unsellable product or an unavailable price', function (string $mutation, CheckoutRefusalReason $reason) {
    $user = User::factory()->create();
    $product = p3d2Product(1_000);
    $cart = p3d2Cart([['product' => $product]], user: $user);

    match ($mutation) {
        'draft' => DB::table('products')->where('id', $product->id)->update(['status' => 'draft']),
        'archived' => DB::table('products')->where('id', $product->id)->update(['status' => 'archived']),
        'trashed' => DB::table('products')->where('id', $product->id)->update(['deleted_at' => now()]),
        'inactive_price' => DB::table('product_prices')->where('product_id', $product->id)->update(['is_active' => false]),
    };

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('w')),
        $reason,
    );

    expect(Order::count())->toBe(0)
        ->and($cart->fresh()->status)->toBe(CartStatus::Active);
})->with([
    ['draft', CheckoutRefusalReason::ProductUnavailable],
    ['archived', CheckoutRefusalReason::ProductUnavailable],
    ['trashed', CheckoutRefusalReason::ProductUnavailable],
    ['inactive_price', CheckoutRefusalReason::PriceUnavailable],
]);

it('refuses a currency the catalogue does not price', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(1_000, 'XOF')]], user: $user);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'EUR', p3d2Key('x')),
        CheckoutRefusalReason::PriceUnavailable,
    );
});

it('refuses a coupon that no longer applies', function () {
    $user = User::factory()->create();
    $coupon = Coupon::factory()->percent()->expired()->create();
    $cart = p3d2Cart([['product' => p3d2Product(10_000)]], user: $user, coupon: $coupon);

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('y')),
        CheckoutRefusalReason::CouponUnavailable,
    );

    expect(Order::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('returns the same order on an identical replay without touching anything', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(7_000)]], user: $user);
    $key = p3d2Key('z');

    $first = p3d2Service()->checkout($user, $cart->public_id, 'XOF', $key);
    $convertedAt = $cart->fresh()->updated_at;

    $second = p3d2Service()->checkout($user, $cart->public_id, 'XOF', $key);

    expect($second->id)->toBe($first->id)
        ->and(Order::count())->toBe(1)
        ->and(DB::table('order_items')->count())->toBe(1)
        ->and($cart->fresh()->status)->toBe(CartStatus::Converted)
        ->and($cart->fresh()->updated_at->eq($convertedAt))->toBeTrue();

    p3d2AssertNoDownstreamWrites();
});

it('replays a bundle order without duplicating its snapshot', function () {
    $user = User::factory()->create();
    [$bundle] = p3d2Bundle(30_000, 3);
    $cart = p3d2Cart([['product' => $bundle]], user: $user);
    $key = p3d2Key('1');

    p3d2Service()->checkout($user, $cart->public_id, 'XOF', $key);
    p3d2Service()->checkout($user, $cart->public_id, 'XOF', $key);

    expect(Order::count())->toBe(1)
        ->and(DB::table('order_item_bundle_components')->count())->toBe(3);
});

it('replays even after the cart was emptied afterwards', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(7_000)]], user: $user);
    $key = p3d2Key('2');

    $first = p3d2Service()->checkout($user, $cart->public_id, 'XOF', $key);

    DB::table('cart_items')->where('cart_id', $cart->id)->delete();

    $second = p3d2Service()->checkout($user, $cart->public_id, 'XOF', $key);

    // The Order, not the mutated cart, is the authoritative truth.
    expect($second->id)->toBe($first->id)
        ->and($second->total_minor)->toBe(7_000);
});

it('refuses a different key on an already checked out cart', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(7_000)]], user: $user);

    p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('3'));

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('4')),
        CheckoutRefusalReason::CartAlreadyCheckedOut,
    );

    expect(Order::count())->toBe(1);
});

it('refuses the same key reused on a different cart, actor, currency or coupon', function (string $variant) {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $coupon = Coupon::factory()->percent(1_000)->create();
    $key = p3d2Key('5');

    $first = p3d2Cart([['product' => p3d2Product(10_000)]], user: $user);
    p3d2Service()->checkout($user, $first->public_id, 'XOF', $key);

    $second = match ($variant) {
        'cart' => p3d2Cart([['product' => p3d2Product(10_000)]], user: $user),
        'actor' => p3d2Cart([['product' => p3d2Product(10_000)]], user: $other),
        'currency' => p3d2Cart([['product' => p3d2Product(10_000, 'EUR')]], user: $user),
        'coupon' => p3d2Cart([['product' => p3d2Product(10_000)]], user: $user, coupon: $coupon),
    };

    $actor = $variant === 'actor' ? $other : $user;
    $currency = $variant === 'currency' ? 'EUR' : 'XOF';

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($actor, $second->public_id, $currency, $key),
        CheckoutRefusalReason::IdempotencyConflict,
    );

    expect(Order::count())->toBe(1)
        ->and($second->fresh()->status)->toBe(CartStatus::Active);
})->with([['cart'], ['actor'], ['currency'], ['coupon']]);

it('never stores or exposes the raw idempotency key', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(7_000)]], user: $user);
    $key = 'RAW-SECRET-IDEMPOTENCY-KEY-0123456789';

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', $key);

    $row = json_encode(DB::table('orders')->where('id', $order->id)->first());

    expect($row)->not->toContain($key)
        ->and($order->checkout_idempotency_hash)->toBe(hash('sha256', $key))
        ->and($order->checkout_idempotency_hash)->toMatch('/\A[0-9a-f]{64}\z/');

    try {
        p3d2Service()->checkout($user, $cart->public_id, 'XOF', $key.'-different');
    } catch (CheckoutException $e) {
        expect($e->getMessage())->not->toContain($key);
    }
});

// ---------------------------------------------------------------------------
// Integrity and rollback
// ---------------------------------------------------------------------------

it('leaves nothing behind and keeps the cart active when the checkout is refused', function () {
    $user = User::factory()->create();
    [$bundle, $children] = p3d2Bundle(30_000, 2);
    $cart = p3d2Cart([
        ['product' => p3d2Product(5_000)],
        ['product' => $bundle],
    ], user: $user);

    $children[0]->delete();

    p3d2ExpectRefusal(
        fn () => p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('6')),
        CheckoutRefusalReason::BundleComponentUnavailable,
    );

    expect(Order::count())->toBe(0)
        ->and(DB::table('order_items')->count())->toBe(0)
        ->and(DB::table('order_item_bundle_components')->count())->toBe(0)
        ->and($cart->fresh()->status)->toBe(CartStatus::Active)
        ->and(DB::table('cart_items')->where('cart_id', $cart->id)->count())->toBe(2);

    p3d2AssertNoDownstreamWrites();
});

it('satisfies the deferred constraint triggers at commit', function () {
    $user = User::factory()->create();
    $cart = p3d2Cart([['product' => p3d2Product(9_999), 'quantity' => 3]], user: $user);

    $order = p3d2Service()->checkout($user, $cart->public_id, 'XOF', p3d2Key('7'));

    // If the deferred triggers had not been satisfied the transaction above
    // could not have committed at all; re-forcing them proves the stored rows
    // still satisfy every invariant.
    DB::transaction(function () use ($order) {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        expect(DB::table('orders')->where('id', $order->id)->exists())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Real concurrency (two PostgreSQL connections)
// ---------------------------------------------------------------------------

/**
 * Real concurrency needs COMMITTED data on two connections, which RefreshDatabase
 * (one open transaction per test) cannot provide. The project's established
 * pattern is a throwaway database seeded by a committed connection, then two
 * real PDO sessions — see P4A1BundlePurchaseSnapshotTest.
 *
 * @param  callable(PDO, PDO, PDO): void  $scenario  (seed, A, B)
 */
function p3d2Concurrency(callable $scenario): void
{
    $harness = new PhaseMigrationHarness('digitrove_p3d2_conc_'.strtolower(Str::random(10)));
    $connection = config('database.connections.pgsql_migration');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName());
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000013_create_download_logs_table.php');

        $scenario(
            new PDO($dsn, $connection['username'], $connection['password'], $options),
            new PDO($dsn, $connection['username'], $connection['password'], $options),
            new PDO($dsn, $connection['username'], $connection['password'], $options),
        );
    } finally {
        $harness->drop();
    }
}

it('blocks a concurrent soft delete of a bundle component behind the child row lock', function () {
    p3d2Concurrency(function (PDO $seed, PDO $a, PDO $b): void {
        $seed->exec("INSERT INTO products (slug,name,type,status,created_at,updated_at) VALUES
            ('cbundle','Bundle','bundle','published',now(),now()),
            ('cchild','Child','ebook','published',now(),now())");
        $seed->exec("INSERT INTO product_bundles (bundle_id, child_product_id, position)
            SELECT p.id, c.id, 0 FROM products p, products c WHERE p.slug='cbundle' AND c.slug='cchild'");

        // A models OrderService: it locks the CHILD product rows (D-031) before
        // validating deleted_at and copying the snapshot.
        $a->beginTransaction();
        $a->exec("SELECT id FROM products WHERE slug='cchild' FOR UPDATE");

        // B tries to soft-delete that very component. It must wait.
        $b->exec("SET lock_timeout = '1000ms'");
        $b->beginTransaction();

        $blocked = null;

        try {
            $b->exec("UPDATE products SET deleted_at = now() WHERE slug='cchild'");
        } catch (PDOException $e) {
            $blocked = $e;
        }

        expect($blocked)->not->toBeNull('A concurrent soft delete was NOT blocked by the child row lock.')
            ->and($blocked->getCode())->toBe('55P03');

        $b->rollBack();
        $a->rollBack();
    });
});

it('serialises two checkouts of the same cart through the cart row lock', function () {
    p3d2Concurrency(function (PDO $seed, PDO $a, PDO $b): void {
        $seed->exec("INSERT INTO carts (public_id, secret_hash, status, expires_at, created_at, updated_at)
            VALUES (gen_random_uuid(), '".hash('sha256', 'conc')."', 'active', now() + interval '1 day', now(), now())");

        $a->beginTransaction();
        $a->exec('SELECT id FROM carts FOR UPDATE');

        $b->exec("SET lock_timeout = '1000ms'");
        $b->beginTransaction();

        $blocked = null;

        try {
            $b->exec('SELECT id FROM carts FOR UPDATE');
        } catch (PDOException $e) {
            $blocked = $e;
        }

        expect($blocked)->not->toBeNull('Two concurrent checkouts of one cart were NOT serialised.')
            ->and($blocked->getCode())->toBe('55P03');

        $b->rollBack();
        $a->rollBack();
    });
});

it('lets PostgreSQL refuse a second order for the same cart', function () {
    p3d2Concurrency(function (PDO $seed, PDO $a, PDO $b): void {
        $seed->exec("INSERT INTO products (slug,name,type,status,created_at,updated_at)
            VALUES ('conc-p','P','ebook','published',now(),now())");
        $seed->exec("INSERT INTO carts (public_id, secret_hash, status, expires_at, created_at, updated_at)
            VALUES (gen_random_uuid(), '".hash('sha256', 'cart2')."', 'active', now() + interval '1 day', now(), now())");

        $insertOrder = static function (PDO $pdo, string $number, string $key): void {
            $pdo->exec("INSERT INTO orders (public_id, order_number, cart_id, checkout_idempotency_hash, customer_email,
                subtotal_minor, discount_minor, tax_minor, total_minor, currency, status, placed_at, expires_at, created_at, updated_at)
                SELECT gen_random_uuid(), '{$number}', c.id, '".hash('sha256', $key)."', 'a@b.test',
                100,0,0,100,'XOF','pending',now(), now() + interval '30 minutes', now(), now() FROM carts c LIMIT 1");
            $pdo->exec("INSERT INTO order_items (order_id, product_id, product_name_snapshot, product_slug_snapshot,
                product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor,
                line_total_minor, currency, created_at, updated_at)
                SELECT o.id, p.id, 'P', 'conc-p', 'ebook', 100, 1, 100, 0, 100, 'XOF', now(), now()
                FROM orders o, products p WHERE o.order_number='{$number}' AND p.slug='conc-p'");
        };

        $a->beginTransaction();
        $insertOrder($a, 'DGT-2026-AAAAAAAAAA', 'key-a');
        $a->commit();

        // A different idempotency key cannot smuggle a second order for the cart:
        // orders_cart_id_unique is the database-level backstop behind
        // CheckoutRefusalReason::CartAlreadyCheckedOut.
        $conflict = null;

        try {
            $b->beginTransaction();
            $insertOrder($b, 'DGT-2026-BBBBBBBBBB', 'key-b');
            $b->commit();
        } catch (PDOException $e) {
            $conflict = $e;
            $b->rollBack();
        }

        expect($conflict)->not->toBeNull()
            ->and($conflict->getCode())->toBe('23505')
            ->and($conflict->getMessage())->toContain('orders_cart_id_unique');
    });
});
