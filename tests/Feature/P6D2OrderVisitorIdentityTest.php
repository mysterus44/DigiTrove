<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\Cart\GuestVisitorContext;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * THE FOUNDATION OF P6-D2, LOCKED BEFORE ANYTHING IS BUILT ON IT.
 *
 * Affiliate attribution joins a paid order to a touch through the anonymous identity:
 * `orders.visitor_id` ↔ `affiliate_touches.visitor_id`. `OrderService` populates that
 * column (line 375, `$actor instanceof Visitor ? $actor->id : $cart->visitor_id`), but
 * until now NO test asserted it — the D-064 suites create guest orders and never look at
 * the field.
 *
 * That made it an incidental detail of checkout. P6-D2 turns it into a load-bearing
 * invariant, and a behaviour that becomes load-bearing has to be pinned by an explicit
 * test. Otherwise a future refactor could drop the column silently and the whole
 * attribution chain would go quiet rather than fail — affiliates simply stop being paid,
 * with nothing red anywhere.
 *
 * Built through the REAL checkout flow, never a factory shortcut: a fixture that sets
 * `visitor_id` itself would prove only that the fixture works.
 */
function p6d2SellableProduct(): Product
{
    $product = Product::factory()->create([
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XOF',
        'price_minor' => 15_000,
        // Pinned: the factory randomises this and the CHECK demands it exceed the price.
        'compare_at_price_minor' => null,
        'is_active' => true,
    ]);

    return $product->fresh();
}

it('carries the cart visitor identity onto the order the guest checkout creates', function () {
    $product = p6d2SellableProduct();

    // The real storefront flow: add to cart, then check out. Nothing here sets
    // `visitor_id` by hand.
    test()->post(route('cart.items.store', $product->slug))->assertRedirect(route('cart.show'));

    $cart = Cart::query()->sole();
    $sessionVisitorId = session(GuestVisitorContext::SESSION_KEY);

    expect($cart->visitor_id)->not->toBeNull()
        ->and($sessionVisitorId)->toBe($cart->visitor_id);

    test()->post(route('checkout.store'), ['email' => 'invite@example.test']);

    $order = Order::query()->sole();

    // The join P6-D2 depends on. Without it, an attribution can never find its touch.
    expect($order->visitor_id)->not->toBeNull()
        ->and($order->visitor_id)->toBe($cart->visitor_id)
        ->and($order->visitor_id)->toBe($sessionVisitorId)
        // Still a guest: the identity is anonymous, not an account.
        ->and($order->user_id)->toBeNull();
});

/**
 * The column must survive as a real, queryable value — not merely as an Eloquent
 * attribute. The attribution authority will read it in SQL, not through the model.
 */
it('stores the visitor identity as a joinable column, readable in raw SQL', function () {
    $product = p6d2SellableProduct();

    test()->post(route('cart.items.store', $product->slug));
    test()->post(route('checkout.store'), ['email' => 'invite@example.test']);

    $row = DB::table('orders')->select('visitor_id', 'user_id')->sole();
    $cartVisitorId = DB::table('carts')->value('visitor_id');

    expect($row->visitor_id)->toBe($cartVisitorId)
        ->and($row->user_id)->toBeNull()
        // A visitor row genuinely exists behind it: the FK target is not dangling.
        ->and(DB::table('visitors')->where('id', $row->visitor_id)->exists())->toBeTrue();
});

// L'assertion « deux navigateurs distincts » VIVAIT ICI et n'y appartenait pas.
// Elle exige deux transactions PostgreSQL reellement independantes, ce que
// `RefreshDatabase` ne peut pas fournir : tout le test partage une transaction, donc le
// `SET CONSTRAINTS ALL IMMEDIATE` du premier checkout fuit sur le second et fait echouer
// le trigger differe `orders_validate_items_consistency_trigger` avant que les
// `order_items` existent. Elle vit desormais dans
// `P6D2GuestCheckoutSequenceTest`, sous le harnais non transactionnel, ou elle passe.
