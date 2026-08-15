<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Visitor;
use App\Support\PostgresConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Measured, not assumed.
 *
 * Two claims in this gate were reasoned rather than proved, which is exactly the shape of
 * mistake P4-B0 was created to prevent: the P6-C activity trigger was said to fire for the
 * storefront "because triggers do", and the unique index was said to absorb a concurrent
 * double-add "because unique indexes do". Both are now exercised against real PostgreSQL
 * under the real runtime identity.
 */
function guestCartFixture(): array
{
    $visitor = Visitor::query()->create([
        'id' => (string) Str::uuid(), 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $cart = Cart::query()->create([
        'public_id' => (string) Str::uuid(),
        'secret_hash' => hash('sha256', 'fixture-'.Str::uuid()),
        'visitor_id' => $visitor->id,
        'currency' => 'XOF',
        'status' => 'active',
        'expires_at' => now()->addDays(14),
    ]);

    $product = Product::factory()->create([
        'status' => ProductStatus::Published, 'published_at' => now()->subDay(),
    ]);

    ProductPrice::factory()->create([
        'product_id' => $product->id, 'currency' => 'XOF', 'price_minor' => 15_000, 'compare_at_price_minor' => null, 'is_active' => true,
    ]);

    return ['cart' => $cart, 'product' => $product];
}

function cartLastActivity(int $cartId): string
{
    return (string) DB::table('carts')->where('id', $cartId)->value('last_activity_at');
}

// ── The P6-C trigger, under the identity the storefront actually uses ───────────

/**
 * `touch_cart_last_activity()` is owned by `digitrove_crm_executor` and carries
 * `REVOKE ALL … FROM digitrove_runtime`. The storefront writes `cart_items` as
 * `digitrove_runtime`. Whether the trigger still fires is a PostgreSQL semantics question
 * — a trigger runs as part of the statement, not as a call the writer must be allowed to
 * make — and this test settles it by measurement instead of by argument.
 */
it('fires the P6-C activity trigger for a runtime write that cannot execute its function', function () {
    $identity = DB::selectOne('SELECT session_user, current_user');

    // The premise: this really is the restricted role, and it really has no EXECUTE.
    expect($identity->session_user)->toBe('digitrove_runtime')
        ->and($identity->current_user)->toBe('digitrove_runtime')
        ->and((bool) DB::selectOne(
            "SELECT has_function_privilege('digitrove_runtime', 'public.touch_cart_last_activity()', 'EXECUTE') AS allowed"
        )->allowed)->toBeFalse();

    $fixture = guestCartFixture();
    $cartId = $fixture['cart']->id;

    DB::table('carts')->where('id', $cartId)->update(['last_activity_at' => now()->subDays(3)]);
    $before = cartLastActivity($cartId);

    $item = CartItem::query()->create([
        'cart_id' => $cartId, 'product_id' => $fixture['product']->id, 'quantity' => 1,
    ]);

    $afterInsert = cartLastActivity($cartId);
    expect($afterInsert)->not->toBe($before);

    // Backdated again before the DELETE. `last_activity_at` is `timestamptz(0)`, so an
    // insert and a delete in the same second produce the SAME stored value — comparing
    // them directly would report a working trigger as broken.
    DB::table('carts')->where('id', $cartId)->update(['last_activity_at' => now()->subDays(3)]);
    $beforeDelete = cartLastActivity($cartId);

    $item->delete();

    // DELETE moves it too: the trigger is AFTER INSERT OR UPDATE OR DELETE.
    expect(cartLastActivity($cartId))->not->toBe($beforeDelete);
});

/**
 * The other half of the same invariant, and the reason the storefront never revives a
 * dead cart: item churn on a non-active cart must NOT move the clock.
 */
it('leaves the activity clock alone when the cart is no longer active', function (string $status) {
    $fixture = guestCartFixture();
    $cartId = $fixture['cart']->id;

    DB::table('carts')->where('id', $cartId)->update([
        'status' => $status, 'last_activity_at' => now()->subDays(3),
    ]);

    $before = cartLastActivity($cartId);

    CartItem::query()->create([
        'cart_id' => $cartId, 'product_id' => $fixture['product']->id, 'quantity' => 1,
    ]);

    expect(cartLastActivity($cartId))->toBe($before);
})->with(['converted', 'abandoned', 'expired']);

// ── The unique index as the real backstop ───────────────────────────────────────

// NOT PROVEN HERE: a true two-connection race on the unique index. `RefreshesDatabaseAsMigrator`
// wraps each test in a transaction, so a `commit()` inside it only releases a savepoint — the
// outer transaction keeps the row lock and the second connection times out (57014) instead of
// seeing 23505. Proving it would need the non-transactional harness P6-D1.1 used. What IS
// proven below is the part the service depends on: PostgreSQL rejects the duplicate with 23505
// on the exact named constraint, and the classification refuses to be fooled by another name.

/**
 * And the service treats that verdict as success rather than a 500: the row it wanted
 * already exists. Only THIS constraint is tolerated — any other database failure still
 * propagates, so a broken foreign key or a check violation is never swallowed.
 */
it('classifies the cart-item collision by SQLSTATE and constraint, never by message text', function () {
    $fixture = guestCartFixture();

    DB::table('cart_items')->insert([
        'cart_id' => $fixture['cart']->id, 'product_id' => $fixture['product']->id,
        'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $collision = null;

    try {
        DB::table('cart_items')->insert([
            'cart_id' => $fixture['cart']->id, 'product_id' => $fixture['product']->id,
            'quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    } catch (QueryException $exception) {
        $collision = $exception;
    }

    expect($collision)->toBeInstanceOf(QueryException::class)
        ->and(PostgresConstraintViolation::isUniqueViolationOf($collision, 'cart_items_cart_product_unique'))->toBeTrue()
        // A forged name is not enough: the classification demands the real 23505 pair.
        ->and(PostgresConstraintViolation::isUniqueViolationOf($collision, 'carts_public_id_unique'))->toBeFalse();
});
