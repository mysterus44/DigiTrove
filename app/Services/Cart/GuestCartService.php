<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Support\CartConfig;
use App\Support\PostgresConstraintViolation;
use App\Support\SessionStoreGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * The guest cart, as the storefront sees it.
 *
 * OWNERSHIP IS NOT A CHOICE HERE. `carts.secret_hash` is `NOT NULL`, `UNIQUE` and
 * constrained to `^[0-9a-f]{64}$`: a cart without a SHA-256 secret cannot physically
 * exist. A CSPRNG secret is therefore minted at creation, hashed once, and the raw value
 * never leaves this class — no view, no route, no log, no exception message. This gate
 * does NOT use it as a resume capability; `public_id` appears in no route either.
 * Ownership is proved by the session's visitor id alone.
 *
 * NOTHING RESURRECTS. A cart that is converted, abandoned or past its expiry is replaced,
 * never revived. That mirrors the P6-C activity trigger, which deliberately refuses to
 * move `last_activity_at` on a non-active cart precisely so that a burst of item churn
 * cannot bring a dead cart back. Cloning its items would be no better: it would create a
 * cart with no abandonment trace for the reminder ledger to reason about.
 *
 * NO APPLICATION-LEVEL `touch()`. `last_activity_at` is maintained by
 * `cart_items_touch_cart_activity_trigger` at the one layer nothing can bypass. A second
 * writer here would be a second authority, free to disagree with the first.
 */
final class GuestCartService
{
    public const SESSION_KEY = 'storefront.cart_id';

    private const LOCK_SECONDS = 5;

    public function __construct(private readonly GuestVisitorContext $visitors) {}

    /**
     * The usable cart for this browser, or null. READ ONLY — never creates, never mutates.
     *
     * `GET /cart` runs through here, so an expired cart simply stops being returned; it
     * is not transitioned. Sweeping expired rows is deliberately out of this gate (see
     * the report): the read-side check is enough to keep a stale cart invisible.
     */
    public function current(): ?Cart
    {
        $cartId = Session::get(self::SESSION_KEY);
        $visitorId = $this->visitors->currentId();

        if (! is_int($cartId) || $visitorId === null) {
            return null;
        }

        $cart = Cart::query()
            ->where('id', $cartId)
            ->where('visitor_id', $visitorId)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->first();

        return $cart;
    }

    /**
     * Add a product, once.
     *
     * Idempotent by construction: `cart_items_cart_product_unique` already forbids a
     * second row, so two clicks — or two concurrent requests — converge on one line at
     * quantity 1 instead of racing into a 23505. The Redis lock serialises the
     * get-or-create so the two requests cannot each mint a cart either.
     */
    public function add(Product $product): Cart
    {
        SessionStoreGuard::assertUsable();

        $visitor = $this->visitors->forMutation();

        return Cache::lock('guest-cart:'.$visitor->id, self::LOCK_SECONDS)->block(
            self::LOCK_SECONDS,
            function () use ($product): Cart {
                $cart = $this->currentOrNew();

                try {
                    CartItem::query()->firstOrCreate(
                        ['cart_id' => $cart->id, 'product_id' => $product->id],
                        ['quantity' => 1],
                    );
                } catch (QueryException $exception) {
                    // The lock serialises requests that share a session, but it cannot
                    // cover two that raced before either had one. `firstOrCreate` checks
                    // then inserts, so the loser of that race arrives at an INSERT whose
                    // row already exists. The unique index is the structural backstop and
                    // its verdict is exactly what we wanted anyway — one line for this
                    // product — so a confirmed 23505 on THAT constraint is success, not
                    // an error. Any other database failure still propagates.
                    if (! PostgresConstraintViolation::isUniqueViolationOf(
                        $exception,
                        'cart_items_cart_product_unique',
                    )) {
                        throw $exception;
                    }
                }

                return $cart;
            },
        );
    }

    /**
     * Remove a product from THIS browser's cart.
     *
     * Scoped by the cart the session owns, so a forged slug can only ever touch a line
     * the caller already had. Silent when there is nothing to remove: a distinct response
     * for "not yours" would answer a question the caller has no business asking.
     */
    public function remove(Product $product): void
    {
        SessionStoreGuard::assertUsable();

        $cart = $this->current();

        if ($cart === null) {
            return;
        }

        CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->delete();
    }

    /**
     * The cart as it should be displayed.
     *
     * A line whose product is no longer publicly purchasable stays visible but says
     * nothing about itself — not its name, not its price, and above all not WHY. Draft,
     * archived, soft-deleted and "price withdrawn" are indistinguishable from the
     * outside, so the cart cannot become a window onto a product an administrator has
     * taken down. Such a line is excluded from the total and is NOT deleted: a `GET` that
     * quietly erased part of the cart would be a mutation the visitor never asked for.
     *
     * @return array{lines: list<array{product: Product|null, slug: string, priceMinor: int|null, available: bool}>, totalMinor: int, currency: string}
     */
    public function view(): array
    {
        $cart = $this->current();

        if ($cart === null) {
            return ['lines' => [], 'totalMinor' => 0, 'currency' => 'XOF'];
        }

        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->orderBy('id')
            ->get();

        // One query for everything still purchasable. `Product::published()` is the same
        // authority the catalogue uses (D-062); this gate does not define a second one.
        $available = Product::query()
            ->published()
            ->with('activeXofPrice')
            ->whereIn('id', $items->pluck('product_id')->all())
            ->get()
            ->keyBy('id');

        $lines = [];
        $totalMinor = 0;

        foreach ($items as $item) {
            $product = $available->get($item->product_id);
            $priceMinor = $product?->activeXofPrice?->price_minor;

            if ($product !== null && is_int($priceMinor)) {
                // Integer minor units only, quantity fixed at 1. No float ever touches
                // an amount, and no coupon exists on this surface.
                $totalMinor += $priceMinor;
                $lines[] = ['product' => $product, 'slug' => $product->slug, 'priceMinor' => $priceMinor, 'available' => true];

                continue;
            }

            $lines[] = ['product' => null, 'slug' => $this->slugFor($item->product_id), 'priceMinor' => null, 'available' => false];
        }

        return ['lines' => $lines, 'totalMinor' => $totalMinor, 'currency' => 'XOF'];
    }

    /**
     * The slug an unavailable line still needs so it can be removed.
     *
     * It is the only thing about a withdrawn product this surface reveals, and the
     * visitor already had it — they put the product in the cart from a public page.
     */
    private function slugFor(int $productId): string
    {
        $slug = Product::withTrashed()->whereKey($productId)->value('slug');

        return is_string($slug) ? $slug : '';
    }

    /** The session's cart if it is still usable, otherwise a brand new one. */
    private function currentOrNew(): Cart
    {
        return $this->current() ?? $this->create();
    }

    private function create(): Cart
    {
        // Read and validated BEFORE the row exists: a cart persisted with a nonsense
        // expiry would be worse than a refusal, because nothing would ever notice.
        $ttlDays = CartConfig::ttlDays();
        $visitor = $this->visitors->forMutation();

        $cart = DB::transaction(function () use ($ttlDays, $visitor): Cart {
            return Cart::query()->create([
                'public_id' => (string) Str::uuid(),
                // 256 bits from the CSPRNG, hashed immediately. The raw value is never
                // assigned to a property, returned, or interpolated into a message — it
                // exists only as an argument to `hash()` on this line.
                'secret_hash' => hash('sha256', bin2hex(random_bytes(32))),
                'visitor_id' => $visitor->id,
                'user_id' => null,
                'coupon_id' => null,
                'currency' => 'XOF',
                'status' => 'active',
                'expires_at' => now()->addDays($ttlDays),
            ]);
        });

        Session::put(self::SESSION_KEY, $cart->id);

        return $cart;
    }
}
