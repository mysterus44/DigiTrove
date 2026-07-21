<?php

declare(strict_types=1);

use App\Enums\CouponDiscountType;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponCurrencyRule;
use App\Models\CouponRedemption;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\Pricing\PricedLine;
use App\Services\Pricing\PricedQuote;
use App\Services\Pricing\PricingException;
use App\Services\Pricing\PricingRefusalReason;
use App\Services\Pricing\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| P3-D1 — Pricing & Quote Kernel against real PostgreSQL
|--------------------------------------------------------------------------
|
| Every query below runs under the restricted `digitrove_runtime` role (P4-B0,
| D-029.6). The gate is READ-ONLY: a dedicated guard proves that pricing a cart
| never emits a single INSERT / UPDATE / DELETE.
*/

function p3d1Service(): PricingService
{
    return app(PricingService::class);
}

function p3d1PublishedProduct(int $priceMinor, string $currency = 'XOF', bool $activePrice = true): Product
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
 * @param  list<array{product: Product, quantity: int}>  $lines
 */
function p3d1CartWith(array $lines): Cart
{
    $cart = Cart::factory()->create();

    foreach ($lines as $line) {
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $line['product']->id,
            'quantity' => $line['quantity'],
        ]);
    }

    return $cart->fresh();
}

function p3d1ExpectRefusal(Closure $callback, PricingRefusalReason $reason): void
{
    try {
        $callback();
    } catch (PricingException $exception) {
        expect($exception->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException("Expected a pricing refusal [{$reason->value}] but the quote succeeded.");
}

/**
 * Indexes priced lines by product id.
 *
 * @return array<int, PricedLine>
 */
function p3d1LinesByProduct(PricedQuote $quote): array
{
    $indexed = [];

    foreach ($quote->lines as $line) {
        $indexed[$line->productId] = $line;
    }

    return $indexed;
}

/**
 * Captures every SQL statement emitted while the callback runs.
 *
 * The query log is flushed on entry and disabled on exit, so two consecutive
 * captures inside the same test never bleed into each other.
 *
 * @return array{0: mixed, 1: list<string>}
 */
function p3d1CaptureQueries(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $result = $callback();
    } finally {
        DB::disableQueryLog();
    }

    /** @var list<string> $statements */
    $statements = array_map(
        static fn (array $entry): string => $entry['query'],
        DB::getQueryLog(),
    );

    return [$result, $statements];
}

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------

it('runs P3-D1 pricing tests against PostgreSQL under the runtime role', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $identity = DB::selectOne('SELECT current_user AS current_role_name, session_user AS session_role_name');

    expect($identity->current_role_name)->toBe('digitrove_runtime')
        ->and($identity->session_role_name)->toBe('digitrove_runtime');
});

// ---------------------------------------------------------------------------
// Pricing without coupon
// ---------------------------------------------------------------------------

it('prices a single line straight from product_prices', function () {
    $product = p3d1PublishedProduct(12_500);
    $cart = p3d1CartWith([['product' => $product, 'quantity' => 1]]);

    $quote = p3d1Service()->quote($cart, 'XOF');

    expect($quote->currency)->toBe('XOF')
        ->and($quote->subtotalMinor)->toBe(12_500)
        ->and($quote->discountMinor)->toBe(0)
        ->and($quote->taxMinor)->toBe(0)
        ->and($quote->totalMinor)->toBe(12_500)
        ->and($quote->couponSnapshot)->toBeNull()
        ->and($quote->lines)->toHaveCount(1);

    $line = $quote->lines[0];

    expect($line->productId)->toBe($product->id)
        ->and($line->productNameSnapshot)->toBe($product->name)
        ->and($line->productSlugSnapshot)->toBe($product->slug)
        ->and($line->productTypeSnapshot)->toBe($product->type->value)
        ->and($line->unitPriceMinor)->toBe(12_500)
        ->and($line->quantity)->toBe(1)
        ->and($line->lineSubtotalMinor)->toBe(12_500)
        ->and($line->lineDiscountMinor)->toBe(0)
        ->and($line->lineTotalMinor)->toBe(12_500);
});

it('multiplies the unit price by the quantity', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(3_000), 'quantity' => 4]]);

    $quote = p3d1Service()->quote($cart, 'XOF');

    expect($quote->lines[0]->lineSubtotalMinor)->toBe(12_000)
        ->and($quote->subtotalMinor)->toBe(12_000)
        ->and($quote->totalMinor)->toBe(12_000);
});

it('prices several lines and sums them exactly', function () {
    $cart = p3d1CartWith([
        ['product' => p3d1PublishedProduct(1_500), 'quantity' => 2],
        ['product' => p3d1PublishedProduct(2_750), 'quantity' => 3],
    ]);

    $quote = p3d1Service()->quote($cart, 'XOF');

    expect($quote->subtotalMinor)->toBe(11_250)
        ->and($quote->totalMinor)->toBe(11_250)
        ->and($quote->lines)->toHaveCount(2);
});

it('accepts a free product priced at zero', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(0), 'quantity' => 2]]);

    $quote = p3d1Service()->quote($cart, 'XOF');

    expect($quote->subtotalMinor)->toBe(0)
        ->and($quote->totalMinor)->toBe(0)
        ->and($quote->lines[0]->unitPriceMinor)->toBe(0);
});

it('keeps taxMinor at zero because no tax policy exists in this gate', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(9_999), 'quantity' => 1]]);

    expect(p3d1Service()->quote($cart, 'XOF')->taxMinor)->toBe(0);
});

it('reads the price from the database even when the in-memory model was tampered with', function () {
    $product = p3d1PublishedProduct(10_000);
    $cart = p3d1CartWith([['product' => $product, 'quantity' => 1]]);

    // Nothing the caller holds in memory can influence the quote.
    $product->name = 'TAMPERED NAME';
    $product->prices->first()->price_minor = 1;

    $quote = p3d1Service()->quote($cart, 'XOF');

    expect($quote->lines[0]->unitPriceMinor)->toBe(10_000)
        ->and($quote->lines[0]->productNameSnapshot)->not->toBe('TAMPERED NAME');
});

it('refuses an empty cart', function () {
    $cart = Cart::factory()->create();

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart->fresh(), 'XOF'),
        PricingRefusalReason::EmptyCart,
    );
});

// ---------------------------------------------------------------------------
// Price resolution — fail-closed, no fallback
// ---------------------------------------------------------------------------

it('refuses a product that has no price in the requested currency', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(5_000, 'XOF'), 'quantity' => 1]]);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'EUR'),
        PricingRefusalReason::PriceUnavailable,
    );
});

it('never falls back to the price of another currency', function () {
    $product = Product::factory()->create([
        'status' => ProductStatus::Published,
        'published_at' => now()->subDay(),
    ]);

    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'currency' => 'EUR',
        'price_minor' => 4_200,
        'compare_at_price_minor' => null,
        'is_active' => true,
    ]);

    $cart = p3d1CartWith([['product' => $product, 'quantity' => 1]]);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF'),
        PricingRefusalReason::PriceUnavailable,
    );
});

it('refuses an inactive price row', function () {
    $cart = p3d1CartWith([
        ['product' => p3d1PublishedProduct(5_000, 'XOF', activePrice: false), 'quantity' => 1],
    ]);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF'),
        PricingRefusalReason::PriceUnavailable,
    );
});

it('refuses a product that is not published', function (ProductStatus $status) {
    $product = p3d1PublishedProduct(5_000);
    $product->forceFill(['status' => $status])->save();

    $cart = p3d1CartWith([['product' => $product, 'quantity' => 1]]);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF'),
        PricingRefusalReason::ProductUnavailable,
    );
})->with([[ProductStatus::Draft], [ProductStatus::Archived]]);

it('refuses a soft deleted product', function () {
    $product = p3d1PublishedProduct(5_000);
    $cart = p3d1CartWith([['product' => $product, 'quantity' => 1]]);

    $product->delete();

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart->fresh(), 'XOF'),
        PricingRefusalReason::ProductUnavailable,
    );
});

it('refuses a currency that is not in canonical form', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(1_000), 'quantity' => 1]]);

    expect(fn () => p3d1Service()->quote($cart, 'xof'))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Coupons — global scope
// ---------------------------------------------------------------------------

it('applies a global percent coupon and allocates the discount across lines', function () {
    $cart = p3d1CartWith([
        ['product' => p3d1PublishedProduct(10_000), 'quantity' => 1],
        ['product' => p3d1PublishedProduct(5_000), 'quantity' => 1],
    ]);

    $coupon = Coupon::factory()->percent(1_500)->create();

    $quote = p3d1Service()->quote($cart, 'XOF', $coupon);

    $allocated = array_sum(array_map(fn ($line) => $line->lineDiscountMinor, $quote->lines));

    // 15% of 15 000 = 2 250, split 1 500 / 750.
    expect($quote->subtotalMinor)->toBe(15_000)
        ->and($quote->discountMinor)->toBe(2_250)
        ->and($quote->totalMinor)->toBe(12_750)
        ->and($allocated)->toBe(2_250)
        ->and($quote->couponSnapshot)->not->toBeNull()
        ->and($quote->couponSnapshot->couponId)->toBe($coupon->id)
        ->and($quote->couponSnapshot->codeSnapshot)->toBe($coupon->code)
        ->and($quote->couponSnapshot->discountTypeSnapshot)->toBe('percent')
        ->and($quote->couponSnapshot->percentBasisPointsSnapshot)->toBe(1_500)
        ->and($quote->couponSnapshot->fixedAmountMinorSnapshot)->toBeNull();
});

it('applies a global fixed coupon using the currency rule', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(20_000), 'quantity' => 1]]);

    $coupon = Coupon::factory()->fixed()->create();
    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
        'fixed_amount_minor' => 3_000,
        'min_order_minor' => 0,
        'max_discount_minor' => null,
    ]);

    $quote = p3d1Service()->quote($cart, 'XOF', $coupon);

    expect($quote->discountMinor)->toBe(3_000)
        ->and($quote->totalMinor)->toBe(17_000)
        ->and($quote->couponSnapshot->discountTypeSnapshot)->toBe('fixed')
        ->and($quote->couponSnapshot->fixedAmountMinorSnapshot)->toBe(3_000)
        ->and($quote->couponSnapshot->percentBasisPointsSnapshot)->toBeNull();
});

it('caps a fixed coupon at the eligible subtotal instead of going negative', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(2_000), 'quantity' => 1]]);

    $coupon = Coupon::factory()->fixed()->create();
    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
        'fixed_amount_minor' => 999_999,
        'min_order_minor' => 0,
        'max_discount_minor' => null,
    ]);

    $quote = p3d1Service()->quote($cart, 'XOF', $coupon);

    expect($quote->discountMinor)->toBe(2_000)
        ->and($quote->totalMinor)->toBe(0);
});

it('caps a percent coupon at max_discount_minor', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(100_000), 'quantity' => 1]]);

    $coupon = Coupon::factory()->percent(5_000)->create();
    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
        'fixed_amount_minor' => null,
        'min_order_minor' => 0,
        'max_discount_minor' => 7_500,
    ]);

    expect(p3d1Service()->quote($cart, 'XOF', $coupon)->discountMinor)->toBe(7_500);
});

it('refuses a coupon whose currency minimum is not reached', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(1_000), 'quantity' => 1]]);

    $coupon = Coupon::factory()->percent(1_000)->create();
    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
        'fixed_amount_minor' => null,
        'min_order_minor' => 50_000,
        'max_discount_minor' => null,
    ]);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', $coupon),
        PricingRefusalReason::CouponMinimumNotReached,
    );
});

it('refuses a fixed coupon that has no rule for the requested currency', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(20_000), 'quantity' => 1]]);

    $coupon = Coupon::factory()->fixed()->create();
    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'EUR',
        'fixed_amount_minor' => 3_000,
        'min_order_minor' => 0,
        'max_discount_minor' => null,
    ]);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', $coupon),
        PricingRefusalReason::CouponCurrencyRuleMissing,
    );
});

it('refuses a fixed coupon whose currency rule carries no amount', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(20_000), 'quantity' => 1]]);

    $coupon = Coupon::factory()->fixed()->create();
    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
        'fixed_amount_minor' => null,
        'min_order_minor' => 0,
        'max_discount_minor' => null,
    ]);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', $coupon),
        PricingRefusalReason::CouponCurrencyRuleMissing,
    );
});

it('applies a percent coupon that has no currency rule at all', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(10_000), 'quantity' => 1]]);

    $coupon = Coupon::factory()->percent(2_000)->create();

    expect(p3d1Service()->quote($cart, 'XOF', $coupon)->discountMinor)->toBe(2_000);
});

it('refuses an inactive coupon', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(10_000), 'quantity' => 1]]);
    $coupon = Coupon::factory()->percent()->inactive()->create();

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', $coupon),
        PricingRefusalReason::CouponInactive,
    );
});

it('refuses an expired coupon', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(10_000), 'quantity' => 1]]);
    $coupon = Coupon::factory()->percent()->expired()->create();

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', $coupon),
        PricingRefusalReason::CouponExpired,
    );
});

it('refuses a coupon that has not started yet', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(10_000), 'quantity' => 1]]);

    $coupon = Coupon::factory()->percent()->create([
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addMonth(),
    ]);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', $coupon),
        PricingRefusalReason::CouponNotStarted,
    );
});

it('treats the coupon window as inclusive at both boundaries', function () {
    $reference = CarbonImmutable::parse('2026-07-21 12:00:00');
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(10_000), 'quantity' => 1]]);

    $startingNow = Coupon::factory()->percent(1_000)->create([
        'starts_at' => $reference,
        'ends_at' => $reference->addMonth(),
    ]);

    $endingNow = Coupon::factory()->percent(1_000)->create([
        'starts_at' => $reference->subMonth(),
        'ends_at' => $reference,
    ]);

    expect(p3d1Service()->quote($cart, 'XOF', $startingNow, $reference)->discountMinor)->toBe(1_000)
        ->and(p3d1Service()->quote($cart, 'XOF', $endingNow, $reference)->discountMinor)->toBe(1_000);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', $endingNow, $reference->addSecond()),
        PricingRefusalReason::CouponExpired,
    );
});

it('refuses a coupon whose resulting discount would be zero', function () {
    // 1 basis point of 100 minor units floors to 0. The orders CHECK forbids a
    // coupon snapshot paired with discount_minor = 0, so the quote must refuse
    // rather than emit something the checkout could never persist.
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(100), 'quantity' => 1]]);
    $coupon = Coupon::factory()->percent(1)->create();

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', $coupon),
        PricingRefusalReason::CouponDiscountIsZero,
    );
});

// ---------------------------------------------------------------------------
// Coupons — product / category scope (union)
// ---------------------------------------------------------------------------

it('restricts a product scoped coupon to the eligible lines only', function () {
    $eligible = p3d1PublishedProduct(10_000);
    $other = p3d1PublishedProduct(10_000);

    $cart = p3d1CartWith([
        ['product' => $eligible, 'quantity' => 1],
        ['product' => $other, 'quantity' => 1],
    ]);

    $coupon = Coupon::factory()->percent(1_000)->create();
    $coupon->products()->attach($eligible->id);

    $quote = p3d1Service()->quote($cart, 'XOF', $coupon);
    $byProduct = p3d1LinesByProduct($quote);

    // 10% of the eligible 10 000 only.
    expect($quote->subtotalMinor)->toBe(20_000)
        ->and($quote->discountMinor)->toBe(1_000)
        ->and($byProduct[$eligible->id]->lineDiscountMinor)->toBe(1_000)
        ->and($byProduct[$other->id]->lineDiscountMinor)->toBe(0);
});

it('restricts a category scoped coupon to the eligible lines only', function () {
    $category = Category::factory()->create();
    $eligible = p3d1PublishedProduct(8_000);
    $other = p3d1PublishedProduct(8_000);
    $eligible->categories()->attach($category->id);

    $cart = p3d1CartWith([
        ['product' => $eligible, 'quantity' => 1],
        ['product' => $other, 'quantity' => 1],
    ]);

    $coupon = Coupon::factory()->percent(2_500)->create();
    $coupon->categories()->attach($category->id);

    $quote = p3d1Service()->quote($cart, 'XOF', $coupon);
    $byProduct = p3d1LinesByProduct($quote);

    expect($quote->discountMinor)->toBe(2_000)
        ->and($byProduct[$eligible->id]->lineDiscountMinor)->toBe(2_000)
        ->and($byProduct[$other->id]->lineDiscountMinor)->toBe(0);
});

it('combines product and category scopes as a union, never an intersection', function () {
    $category = Category::factory()->create();

    $byProductOnly = p3d1PublishedProduct(1_000);
    $byCategoryOnly = p3d1PublishedProduct(1_000);
    $byNeither = p3d1PublishedProduct(1_000);

    $byCategoryOnly->categories()->attach($category->id);

    $cart = p3d1CartWith([
        ['product' => $byProductOnly, 'quantity' => 1],
        ['product' => $byCategoryOnly, 'quantity' => 1],
        ['product' => $byNeither, 'quantity' => 1],
    ]);

    $coupon = Coupon::factory()->percent(5_000)->create();
    $coupon->products()->attach($byProductOnly->id);
    $coupon->categories()->attach($category->id);

    $quote = p3d1Service()->quote($cart, 'XOF', $coupon);
    $byProduct = p3d1LinesByProduct($quote);

    // Union: two eligible lines of 1 000 -> 50% of 2 000 = 1 000.
    expect($quote->discountMinor)->toBe(1_000)
        ->and($byProduct[$byProductOnly->id]->lineDiscountMinor)->toBe(500)
        ->and($byProduct[$byCategoryOnly->id]->lineDiscountMinor)->toBe(500)
        ->and($byProduct[$byNeither->id]->lineDiscountMinor)->toBe(0);
});

it('counts a product belonging to several eligible categories only once', function () {
    $first = Category::factory()->create();
    $second = Category::factory()->create();
    $product = p3d1PublishedProduct(10_000);
    $product->categories()->attach([$first->id, $second->id]);

    $cart = p3d1CartWith([['product' => $product, 'quantity' => 1]]);

    $coupon = Coupon::factory()->percent(1_000)->create();
    $coupon->categories()->attach([$first->id, $second->id]);

    $quote = p3d1Service()->quote($cart, 'XOF', $coupon);

    expect($quote->lines)->toHaveCount(1)
        ->and($quote->discountMinor)->toBe(1_000)
        ->and($quote->lines[0]->lineDiscountMinor)->toBe(1_000);
});

it('refuses a scoped coupon that matches no line instead of dropping it silently', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(10_000), 'quantity' => 1]]);

    $coupon = Coupon::factory()->percent(1_000)->create();
    $coupon->products()->attach(p3d1PublishedProduct(1_000)->id);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', $coupon),
        PricingRefusalReason::CouponNotApplicable,
    );
});

it('measures the currency minimum on the whole cart but discounts only eligible lines', function () {
    $eligible = p3d1PublishedProduct(10_000);
    $other = p3d1PublishedProduct(40_000);

    $cart = p3d1CartWith([
        ['product' => $eligible, 'quantity' => 1],
        ['product' => $other, 'quantity' => 1],
    ]);

    $coupon = Coupon::factory()->percent(1_000)->create();
    $coupon->products()->attach($eligible->id);

    CouponCurrencyRule::factory()->create([
        'coupon_id' => $coupon->id,
        'currency' => 'XOF',
        'fixed_amount_minor' => null,
        'min_order_minor' => 50_000,
        'max_discount_minor' => null,
    ]);

    // min_order_minor is measured on the 50 000 cart subtotal (the "order"),
    // while the discount base stays the 10 000 eligible subtotal.
    expect(p3d1Service()->quote($cart, 'XOF', $coupon)->discountMinor)->toBe(1_000);
});

// ---------------------------------------------------------------------------
// Adversarial edges
// ---------------------------------------------------------------------------

it('applies a one hundred percent coupon down to a zero total', function () {
    $cart = p3d1CartWith([
        ['product' => p3d1PublishedProduct(7_000), 'quantity' => 1],
        ['product' => p3d1PublishedProduct(3_000), 'quantity' => 1],
    ]);

    $quote = p3d1Service()->quote($cart, 'XOF', Coupon::factory()->percent(10_000)->create());

    expect($quote->subtotalMinor)->toBe(10_000)
        ->and($quote->discountMinor)->toBe(10_000)
        ->and($quote->totalMinor)->toBe(0);

    foreach ($quote->lines as $line) {
        expect($line->lineDiscountMinor)->toBe($line->lineSubtotalMinor)
            ->and($line->lineTotalMinor)->toBe(0);
    }
});

it('discounts a paid line while leaving a free line at zero', function () {
    $free = p3d1PublishedProduct(0);
    $paid = p3d1PublishedProduct(10_000);

    $cart = p3d1CartWith([
        ['product' => $free, 'quantity' => 3],
        ['product' => $paid, 'quantity' => 1],
    ]);

    $quote = p3d1Service()->quote($cart, 'XOF', Coupon::factory()->percent(2_500)->create());
    $byProduct = p3d1LinesByProduct($quote);

    expect($quote->discountMinor)->toBe(2_500)
        ->and($byProduct[$free->id]->lineDiscountMinor)->toBe(0)
        ->and($byProduct[$free->id]->lineTotalMinor)->toBe(0)
        ->and($byProduct[$paid->id]->lineDiscountMinor)->toBe(2_500);
});

it('refuses a coupon on a cart made only of free products', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(0), 'quantity' => 5]]);

    p3d1ExpectRefusal(
        fn () => p3d1Service()->quote($cart, 'XOF', Coupon::factory()->percent(5_000)->create()),
        PricingRefusalReason::CouponDiscountIsZero,
    );
});

it('stays exact on a high quantity instead of drifting', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(999_983), 'quantity' => 100_000]]);

    $quote = p3d1Service()->quote($cart, 'XOF');

    expect($quote->subtotalMinor)->toBe(99_998_300_000)
        ->and($quote->lines[0]->lineSubtotalMinor)->toBe(999_983 * 100_000);
});

it('refuses a line whose subtotal would overflow the integer range', function () {
    $cart = p3d1CartWith([
        ['product' => p3d1PublishedProduct(PHP_INT_MAX), 'quantity' => 2],
    ]);

    expect(fn () => p3d1Service()->quote($cart, 'XOF'))->toThrow(OverflowException::class);
});

// ---------------------------------------------------------------------------
// Compatibility with the future Order / OrderItem invariants
// ---------------------------------------------------------------------------

it('produces a quote that satisfies every future order and order_item formula', function () {
    $cart = p3d1CartWith([
        ['product' => p3d1PublishedProduct(1_333), 'quantity' => 3],
        ['product' => p3d1PublishedProduct(2_667), 'quantity' => 1],
        ['product' => p3d1PublishedProduct(4_001), 'quantity' => 2],
    ]);

    $quote = p3d1Service()->quote($cart, 'XOF', Coupon::factory()->percent(1_234)->create());

    $lineSubtotals = 0;
    $lineDiscounts = 0;
    $lineTotals = 0;

    foreach ($quote->lines as $line) {
        expect($line->lineSubtotalMinor)->toBe($line->unitPriceMinor * $line->quantity)
            ->and($line->lineTotalMinor)->toBe($line->lineSubtotalMinor - $line->lineDiscountMinor)
            ->and($line->lineDiscountMinor)->toBeGreaterThanOrEqual(0)
            ->and($line->lineDiscountMinor)->toBeLessThanOrEqual($line->lineSubtotalMinor)
            ->and($line->lineTotalMinor)->toBeGreaterThanOrEqual(0);

        $lineSubtotals += $line->lineSubtotalMinor;
        $lineDiscounts += $line->lineDiscountMinor;
        $lineTotals += $line->lineTotalMinor;
    }

    // orders CHECK constraints + validate_order_items_consistency (deferred).
    expect($lineSubtotals)->toBe($quote->subtotalMinor)
        ->and($lineDiscounts)->toBe($quote->discountMinor)
        ->and($lineTotals + $quote->taxMinor)->toBe($quote->totalMinor)
        ->and($quote->totalMinor)->toBe($quote->subtotalMinor - $quote->discountMinor + $quote->taxMinor)
        ->and($quote->discountMinor)->toBeLessThanOrEqual($quote->subtotalMinor)
        ->and($quote->discountMinor)->toBeGreaterThan(0)
        ->and($quote->couponSnapshot)->not->toBeNull();
});

it('pairs a non null coupon snapshot with a strictly positive discount', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(10_000), 'quantity' => 1]]);

    $withoutCoupon = p3d1Service()->quote($cart, 'XOF');
    $withCoupon = p3d1Service()->quote($cart, 'XOF', Coupon::factory()->percent(1_000)->create());

    // orders_coupon_snapshot_consistency_check: snapshot <=> discount_minor > 0.
    expect($withoutCoupon->couponSnapshot)->toBeNull()
        ->and($withoutCoupon->discountMinor)->toBe(0)
        ->and($withCoupon->couponSnapshot)->not->toBeNull()
        ->and($withCoupon->discountMinor)->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// Read-only guarantee
// ---------------------------------------------------------------------------

it('never emits a write statement while pricing a cart', function () {
    $product = p3d1PublishedProduct(10_000);
    $cart = p3d1CartWith([['product' => $product, 'quantity' => 2]]);
    $coupon = Coupon::factory()->percent(1_000)->create();
    $coupon->products()->attach($product->id);

    [, $statements] = p3d1CaptureQueries(fn () => p3d1Service()->quote($cart, 'XOF', $coupon));

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect($sql)->not->toMatch('/^\s*(insert|update|delete|truncate|merge)\b/i')
            ->and($sql)->not->toMatch('/\bfor\s+update\b/i');
    }
});

it('leaves the cart, its lines and the coupon counters untouched', function () {
    $product = p3d1PublishedProduct(10_000);
    $cart = p3d1CartWith([['product' => $product, 'quantity' => 2]]);
    $coupon = Coupon::factory()->percent(1_000)->create();

    $cartBefore = DB::table('carts')->where('id', $cart->id)->get()->toJson();
    $itemsBefore = DB::table('cart_items')->where('cart_id', $cart->id)->orderBy('id')->get()->toJson();
    $couponBefore = DB::table('coupons')->where('id', $coupon->id)->get()->toJson();
    $productsBefore = DB::table('products')->orderBy('id')->get()->toJson();
    $pricesBefore = DB::table('product_prices')->orderBy('id')->get()->toJson();

    p3d1Service()->quote($cart, 'XOF', $coupon);

    expect(DB::table('carts')->where('id', $cart->id)->get()->toJson())->toBe($cartBefore)
        ->and(DB::table('cart_items')->where('cart_id', $cart->id)->orderBy('id')->get()->toJson())->toBe($itemsBefore)
        ->and(DB::table('coupons')->where('id', $coupon->id)->get()->toJson())->toBe($couponBefore)
        ->and(DB::table('products')->orderBy('id')->get()->toJson())->toBe($productsBefore)
        ->and(DB::table('product_prices')->orderBy('id')->get()->toJson())->toBe($pricesBefore)
        ->and($coupon->fresh()->redemptions_count)->toBe(0);
});

it('creates no order, redemption, payment, grant or download log', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(10_000), 'quantity' => 1]]);

    p3d1Service()->quote($cart, 'XOF', Coupon::factory()->percent(1_000)->create());

    expect(CouponRedemption::count())->toBe(0)
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('order_items')->count())->toBe(0)
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('download_grants')->count())->toBe(0)
        ->and(DB::table('download_logs')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Loading strategy
// ---------------------------------------------------------------------------

it('prices a multi line cart without any lazy relational loading', function () {
    $category = Category::factory()->create();

    $lines = [];
    for ($i = 0; $i < 5; $i++) {
        $product = p3d1PublishedProduct(1_000 + $i);
        $product->categories()->attach($category->id);
        $lines[] = ['product' => $product, 'quantity' => $i + 1];
    }

    $cart = p3d1CartWith($lines);

    $coupon = Coupon::factory()->percent(1_000)->create();
    $coupon->categories()->attach($category->id);

    Model::preventLazyLoading();

    try {
        $quote = p3d1Service()->quote($cart, 'XOF', $coupon);
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($quote->lines)->toHaveCount(5)
        ->and($quote->discountMinor)->toBeGreaterThan(0);
});

it('keeps the query count independent from the number of cart lines', function () {
    $makeCart = function (int $lineCount): Cart {
        $lines = [];
        for ($i = 0; $i < $lineCount; $i++) {
            $lines[] = ['product' => p3d1PublishedProduct(1_000 + $i), 'quantity' => 1];
        }

        return p3d1CartWith($lines);
    };

    $small = $makeCart(2);
    $large = $makeCart(8);

    [, $smallStatements] = p3d1CaptureQueries(fn () => p3d1Service()->quote($small, 'XOF'));
    [, $largeStatements] = p3d1CaptureQueries(fn () => p3d1Service()->quote($large, 'XOF'));

    // A fixed set of eager queries: quadrupling the cart must not add a single
    // statement. This proves the absence of N+1 without hardcoding a number
    // that a legitimate framework change would break.
    expect(count($largeStatements))->toBe(count($smallStatements))
        ->and(count($smallStatements))->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------------
// Determinism
// ---------------------------------------------------------------------------

it('produces an identical quote when priced twice', function () {
    $cart = p3d1CartWith([
        ['product' => p3d1PublishedProduct(1_333), 'quantity' => 3],
        ['product' => p3d1PublishedProduct(2_667), 'quantity' => 7],
    ]);

    $coupon = Coupon::factory()->percent(3_333)->create();
    $reference = CarbonImmutable::parse('2026-07-21 12:00:00');

    $first = p3d1Service()->quote($cart->fresh(), 'XOF', $coupon, $reference);
    $second = p3d1Service()->quote($cart->fresh(), 'XOF', $coupon, $reference);

    $shape = fn ($quote) => array_map(
        fn ($line) => [$line->productId, $line->lineDiscountMinor, $line->lineTotalMinor],
        $quote->lines,
    );

    expect($second->discountMinor)->toBe($first->discountMinor)
        ->and($shape($second))->toBe($shape($first));
});

it('exposes the coupon discount type as the canonical enum value', function () {
    $cart = p3d1CartWith([['product' => p3d1PublishedProduct(10_000), 'quantity' => 1]]);
    $coupon = Coupon::factory()->percent(1_000)->create();

    $snapshot = p3d1Service()->quote($cart, 'XOF', $coupon)->couponSnapshot;

    expect($snapshot->discountTypeSnapshot)->toBe(CouponDiscountType::Percent->value);
});
