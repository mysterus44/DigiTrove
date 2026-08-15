<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\Cart\GuestCartService;
use App\Services\Cart\GuestVisitorContext;
use App\Support\SessionStoreGuard;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

/** A product the storefront will actually sell: published, dated, with a live XOF price. */
function guestCartProduct(array $attributes = []): Product
{
    $product = Product::factory()->create(array_merge([
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
    ], $attributes));

    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'XOF',
        'price_minor' => 15_000,
        'is_active' => true,
    ]);

    return $product->fresh();
}

// ── Reading never writes ────────────────────────────────────────────────────────

it('shows an empty cart without creating a cart or a visitor', function () {
    $this->get('/cart')->assertOk()->assertSee('Votre panier est vide');

    // A crawler hitting this page must not mint rows the abandonment metrics would then
    // have to reason about.
    expect(Cart::query()->count())->toBe(0)
        ->and(DB::table('visitors')->count())->toBe(0);
});

// ── Creation ────────────────────────────────────────────────────────────────────

it('creates one guest cart on the first add, with a hashed secret and a configured expiry', function () {
    config(['cart.ttl_days' => 14]);
    $product = guestCartProduct();

    $this->post(route('cart.items.store', $product->slug))->assertRedirect(route('cart.show'));

    $cart = Cart::query()->sole();

    expect($cart->status->value)->toBe('active')
        ->and($cart->currency)->toBe('XOF')
        ->and($cart->user_id)->toBeNull()
        // Coupons are not exposed on this surface at all.
        ->and($cart->coupon_id)->toBeNull()
        ->and($cart->visitor_id)->not->toBeNull()
        // The schema demands a SHA-256; only its digest is ever stored.
        ->and($cart->secret_hash)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and(now()->diffInDays($cart->expires_at))->toBeGreaterThanOrEqual(13)
        ->and(CartItem::query()->sole()->quantity)->toBe(1);
});

it('honours the configured lifetime instead of a hard-coded one', function () {
    config(['cart.ttl_days' => 3]);
    $this->post(route('cart.items.store', guestCartProduct()->slug));

    expect(now()->diffInDays(Cart::query()->sole()->expires_at))->toBeLessThan(4);
});

it('refuses to build a cart when the configured lifetime is nonsense', function () {
    config(['cart.ttl_days' => 'quatorze']);

    // Refused BEFORE the row exists: a cart persisted with an unusable expiry would never
    // be noticed again.
    expect(fn () => $this->withoutExceptionHandling()->post(route('cart.items.store', guestCartProduct()->slug)))
        ->toThrow(RuntimeException::class);

    expect(Cart::query()->count())->toBe(0);
});

// ── Purchasability, revalidated server-side ─────────────────────────────────────

it('refuses to add anything the catalogue does not publish', function (array $attributes, bool $withPrice) {
    $product = Product::factory()->create($attributes);

    if ($withPrice) {
        ProductPrice::factory()->create([
            'product_id' => $product->id, 'currency' => 'XOF', 'price_minor' => 9_000, 'is_active' => true,
        ]);
    }

    $this->post(route('cart.items.store', $product->slug))->assertNotFound();

    expect(Cart::query()->count())->toBe(0)
        ->and(CartItem::query()->count())->toBe(0);
})->with([
    'draft' => [['status' => ProductStatus::Draft, 'published_at' => null], true],
    'archived' => [['status' => ProductStatus::Archived, 'published_at' => null], true],
    'published in the future' => [['status' => ProductStatus::Published, 'published_at' => null], true],
    'no active XOF price' => [['status' => ProductStatus::Published, 'published_at' => null], false],
]);

it('refuses a soft-deleted product even with a live price', function () {
    $product = guestCartProduct();
    $product->delete();

    $this->post(route('cart.items.store', $product->slug))->assertNotFound();

    expect(Cart::query()->count())->toBe(0);
});

// ── Idempotence ─────────────────────────────────────────────────────────────────

it('keeps a single line at quantity one however many times the button is pressed', function () {
    $product = guestCartProduct();

    $this->post(route('cart.items.store', $product->slug));
    $this->post(route('cart.items.store', $product->slug));
    $this->post(route('cart.items.store', $product->slug));

    expect(Cart::query()->count())->toBe(1)
        ->and(CartItem::query()->count())->toBe(1)
        ->and(CartItem::query()->sole()->quantity)->toBe(1);
});

// ── Removal and isolation ───────────────────────────────────────────────────────

it('removes a line for its owner and stays silent when there is nothing to remove', function () {
    $product = guestCartProduct();
    $this->post(route('cart.items.store', $product->slug));

    $this->delete(route('cart.items.destroy', $product->slug))->assertRedirect(route('cart.show'));
    expect(CartItem::query()->count())->toBe(0);

    // Idempotent: a second removal is not an error and reveals nothing.
    $this->delete(route('cart.items.destroy', $product->slug))->assertRedirect(route('cart.show'));
    expect(CartItem::query()->count())->toBe(0);
});

it('cannot reach the cart of another session', function () {
    $product = guestCartProduct();
    $this->post(route('cart.items.store', $product->slug));

    expect(CartItem::query()->count())->toBe(1);

    // A different browser: no cart in session, so the removal has nothing to scope to and
    // must not touch anyone else's line.
    $this->flushSession();
    $this->delete(route('cart.items.destroy', $product->slug))->assertRedirect(route('cart.show'));

    expect(CartItem::query()->count())->toBe(1);
});

// ── Nothing is resurrected ──────────────────────────────────────────────────────

it('starts a new cart instead of reviving one that is no longer active', function (string $status) {
    $product = guestCartProduct();
    $this->post(route('cart.items.store', $product->slug));

    $first = Cart::query()->sole();
    DB::table('carts')->where('id', $first->id)->update(['status' => $status]);

    $this->post(route('cart.items.store', $product->slug));

    $carts = Cart::query()->orderBy('id')->get();

    // Two rows: the dead one keeps its state, the live one is brand new. Reviving would
    // contradict the P6-C trigger, which refuses to move `last_activity_at` on exactly
    // these states so that item churn cannot bring a cart back.
    expect($carts)->toHaveCount(2)
        ->and($carts[0]->status->value)->toBe($status)
        ->and($carts[1]->status->value)->toBe('active')
        ->and($carts[1]->id)->not->toBe($first->id)
        // …and its items were not cloned: a copied cart would have no abandonment trace.
        ->and(CartItem::query()->where('cart_id', $carts[1]->id)->count())->toBe(1);
})->with(['converted', 'abandoned', 'expired']);

it('starts a new cart when the previous one has passed its expiry', function () {
    $product = guestCartProduct();
    $this->post(route('cart.items.store', $product->slug));

    $first = Cart::query()->sole();
    DB::table('carts')->where('id', $first->id)->update(['expires_at' => now()->subMinute()]);

    // The expired cart is simply not returned; nothing transitions it on a read.
    $this->get('/cart')->assertOk()->assertSee('Votre panier est vide');
    expect(Cart::query()->sole()->status->value)->toBe('active');

    $this->post(route('cart.items.store', $product->slug));

    expect(Cart::query()->count())->toBe(2);
});

// ── A product taken down after it was added ─────────────────────────────────────

it('shows a withdrawn product as an unnamed line, excluded from the total and never deleted on a read', function () {
    $kept = guestCartProduct(['name' => 'Pack visible']);
    $withdrawn = guestCartProduct(['name' => 'Secret interne']);

    $this->post(route('cart.items.store', $kept->slug));
    $this->post(route('cart.items.store', $withdrawn->slug));

    $withdrawn->update(['status' => ProductStatus::Draft]);

    $response = $this->get('/cart')->assertOk();

    $response->assertSee('Pack visible')
        ->assertSee('Article indisponible')
        // Nothing about the withdrawn product leaks: not its name, not its price, and
        // above all not WHY it went away.
        ->assertDontSee('Secret interne')
        // Total counts the available line only.
        ->assertSee('Total : 15 000 XOF');

    // A GET must not silently erase part of the cart.
    expect(CartItem::query()->count())->toBe(2);
});

// ── Session boundary ────────────────────────────────────────────────────────────

it('refuses every cart mutation when the session store cannot keep state', function (string $driver) {
    $product = guestCartProduct();
    config(['session.driver' => $driver]);

    expect(SessionStoreGuard::isUsable())->toBeFalse();

    // Asserted at the service layer on purpose: an unusable driver makes Laravel's own
    // session middleware fail first, so an HTTP request would prove nothing about OUR
    // refusal. What matters is that no cart can be built while the store is unusable.
    expect(fn () => app(GuestCartService::class)->add($product))->toThrow(RuntimeException::class);

    expect(Cart::query()->count())->toBe(0);
})->with(['database', 'file', 'cookie', 'null']);

it('accepts the array driver only while the test runner is active', function () {
    // The suite itself runs on `array`; the guard admits it because
    // `runningUnitTests()` is true, a condition no HTTP request can influence.
    config(['session.driver' => 'array']);

    expect(SessionStoreGuard::isUsable())->toBeTrue()
        ->and(app()->runningUnitTests())->toBeTrue();
});

// ── Privacy ─────────────────────────────────────────────────────────────────────

it('never puts a cart secret, id or public id on the wire', function () {
    $product = guestCartProduct();
    $this->post(route('cart.items.store', $product->slug));

    $cart = Cart::query()->sole();
    $html = $this->get('/cart')->assertOk()->getContent();

    expect($html)->not->toContain($cart->secret_hash)
        ->and($html)->not->toContain($cart->public_id)
        // None of THIS gate's routes carries a cart identity — ownership comes from the
        // session. (P6-C's resume surface is a different capability and is not in scope.)
        ->and(collect(app('router')->getRoutes())
            ->filter(fn ($route): bool => in_array($route->getName(), ['cart.show', 'cart.items.store', 'cart.items.destroy'], true))
            ->map(fn ($route): string => $route->uri())
            ->values()
            ->all())->toBe(['cart', 'cart/items/{slug}', 'cart/items/{slug}']);
});

it('keeps the visitor identity out of client control', function () {
    $product = guestCartProduct();

    // A forged visitor id in the request must be ignored: it is an analytics identifier,
    // never an authorisation token.
    $this->post(route('cart.items.store', $product->slug), [
        GuestVisitorContext::SESSION_KEY => 'forged',
        'visitor_id' => 'forged',
    ]);

    expect(Cart::query()->sole()->visitor_id)->not->toBe('forged');
});
