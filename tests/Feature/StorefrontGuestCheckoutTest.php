<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\Checkout\GuestCheckoutSession;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

function checkoutProduct(): Product
{
    $product = Product::factory()->create([
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    ProductPrice::factory()->create([
        'product_id' => $product->id, 'currency' => 'XOF', 'price_minor' => 15_000, 'compare_at_price_minor' => null, 'is_active' => true,
    ]);

    return $product->fresh();
}

/** A browser that already has a guest cart holding one sellable product. */
function browserWithCart(): Product
{
    $product = checkoutProduct();
    test()->post(route('cart.items.store', $product->slug))->assertRedirect(route('cart.show'));

    return $product;
}

// ── The form ────────────────────────────────────────────────────────────────────

it('shows the checkout form for a cart that has something in it', function () {
    browserWithCart();

    test()->get(route('checkout.show'))
        ->assertOk()
        ->assertSee('Adresse e-mail')
        ->assertSee('15 000 XOF');
});

it('sends an empty cart away instead of offering a checkout', function () {
    test()->get(route('checkout.show'))->assertOk()->assertSee('Votre panier est vide');
});

it('accepts nothing from the client but the email address', function () {
    browserWithCart();

    // Prices, totals and currency are the authorities' to decide; supplying them must
    // change nothing at all.
    test()->post(route('checkout.store'), [
        'email' => 'acheteur@example.test',
        'total_minor' => 1,
        'currency' => 'EUR',
        'price_minor' => 1,
        'discount_minor' => 14_999,
    ]);

    $order = Order::query()->sole();

    expect($order->currency)->toBe('XOF')
        ->and((int) $order->total_minor)->toBe(15_000)
        ->and((string) $order->customer_email)->toBe('acheteur@example.test');
});

it('refuses a malformed email without creating anything', function (string $email) {
    browserWithCart();

    test()->post(route('checkout.store'), ['email' => $email])->assertSessionHasErrors('email');

    expect(Order::query()->count())->toBe(0);
})->with(['', 'pas-un-email', 'a@', '@example.test']);

// ── The order ───────────────────────────────────────────────────────────────────

it('creates a guest order with no user account and remembers it in the session', function () {
    browserWithCart();

    test()->post(route('checkout.store'), ['email' => 'invite@example.test']);

    $order = Order::query()->sole();

    expect($order->user_id)->toBeNull()
        ->and((string) $order->customer_email)->toBe('invite@example.test')
        // The order number is generated, readable, and never used as a route parameter.
        ->and($order->order_number)->toMatch('/\ADGT-\d{4}-[0-9A-HJKMNP-TV-Z]{10}\z/')
        ->and(session(GuestCheckoutSession::SESSION_KEY))->toBe((string) $order->public_id);

    // The cart is consumed by the authority, not by the storefront.
    expect(Cart::query()->sole()->status->value)->toBe('converted');
});

it('sends the buyer back to the cart with a generic message when pricing refuses', function () {
    $product = browserWithCart();

    // The product stops being sellable between cart and checkout. `PricingService` is
    // fail-closed here and refuses the WHOLE quote — which is the correct behaviour.
    $product->update(['status' => ProductStatus::Draft]);

    $response = test()->post(route('checkout.store'), ['email' => 'invite@example.test']);

    $response->assertRedirect(route('cart.show'))->assertSessionHasErrors('email');

    // Generic on purpose: naming the line would report the catalogue state of a product
    // an administrator has just withdrawn.
    $message = session('errors')->first('email');

    expect($message)->not->toContain($product->name)
        ->and($message)->not->toContain('draft')
        ->and($message)->not->toContain('published')
        ->and(Order::query()->count())->toBe(0);
});

// ── The provider return NEVER confirms ──────────────────────────────────────────

/**
 * THE test of this gate. CinetPay returns the buyer to a URL under our control, and every
 * parameter on it is attacker-supplied. A forged success must move nothing.
 */
it('never confirms a payment from browser return parameters', function () {
    browserWithCart();
    test()->post(route('checkout.store'), ['email' => 'invite@example.test']);

    $order = Order::query()->sole();
    $before = DB::table('orders')->where('id', $order->id)->first();

    foreach ([
        ['status' => 'ACCEPTED'],
        ['status' => 'ACCEPTED', 'cpm_trans_status' => 'ACCEPTED', 'cpm_amount' => '15000'],
        ['transaction_id' => (string) $order->public_id, 'status' => 'SUCCESS', 'paid' => '1'],
    ] as $forged) {
        test()->get(route('checkout.status', ['order' => $order->public_id]).'?'.http_build_query($forged))
            ->assertOk();
    }

    $after = DB::table('orders')->where('id', $order->id)->first();

    // Byte for byte the same row: no status change, no paid_at, no updated_at movement.
    expect((array) $after)->toBe((array) $before)
        ->and(DB::table('payments')->where('order_id', $order->id)->where('status', 'succeeded')->count())->toBe(0)
        ->and(DB::table('download_grants')->count())->toBe(0);
});

it('never caches an order status', function () {
    browserWithCart();
    test()->post(route('checkout.store'), ['email' => 'invite@example.test']);

    $order = Order::query()->sole();

    $header = test()->get(route('checkout.status', ['order' => $order->public_id]))
        ->assertOk()
        ->headers->get('Cache-Control');

    // Asserted directive by directive: Laravel normalises and reorders them, so an exact
    // string comparison would break on a framework detail rather than on the guarantee.
    foreach (['no-store', 'no-cache', 'private', 'must-revalidate'] as $directive) {
        expect($header)->toContain($directive);
    }
});

// ── Session persistence across the provider round trip ──────────────────────────

/**
 * The session must survive the trip out to CinetPay and back. Proven across SEPARATE
 * request cycles of the test client — the checkout POST, then a GET on the static return
 * route with no parameter at all, which can only work if the cookie carried the session.
 */
it('still knows the order when the buyer comes back from the provider', function () {
    browserWithCart();
    test()->post(route('checkout.store'), ['email' => 'invite@example.test']);

    $order = Order::query()->sole();

    // A brand-new request cycle. `/checkout/return` takes NO parameter — the only way it
    // can find the order is the session cookie the client kept.
    test()->get(route('checkout.return'))
        ->assertRedirect(route('checkout.status', ['order' => $order->public_id]));

    test()->get(route('checkout.status', ['order' => $order->public_id]))
        ->assertOk()
        ->assertSee($order->order_number);
});

it('sends a returning buyer with no session to the cart rather than guessing', function () {
    test()->get(route('checkout.return'))->assertRedirect(route('cart.show'));
});

// ── Ownership ───────────────────────────────────────────────────────────────────

it('gives the same flat 404 for someone else\'s order and for one that never existed', function () {
    browserWithCart();
    test()->post(route('checkout.store'), ['email' => 'premier@example.test']);
    $other = Order::query()->sole();

    // A different browser, holding a REAL public id it did not create.
    test()->flushSession();

    $foreign = test()->get(route('checkout.status', ['order' => $other->public_id]));
    $absent = test()->get(route('checkout.status', ['order' => (string) Str::uuid()]));

    // Identical responses: the route must not become an oracle answering "does this
    // order exist?".
    expect($foreign->status())->toBe(404)
        ->and($absent->status())->toBe(404)
        ->and($foreign->getContent())->toBe($absent->getContent());
});

it('keeps order_number out of every route it registers', function () {
    $uris = collect(app('router')->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'checkout.'))
        ->map(fn ($route): string => $route->uri())
        ->values()
        ->all();

    sort($uris);

    // `public_id` only. `order_number` is readable and dictable by phone, which makes it
    // exactly the wrong thing to put in a path.
    expect($uris)->toBe(['checkout', 'checkout', 'checkout/return', 'checkout/{order}/status']);
});
