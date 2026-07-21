<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\CouponDiscountType;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponCurrencyRule;
use App\Models\Product;
use App\Support\IntegerMath;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Turns a persisted cart into an immutable, exact quote (P3-D1, D-030).
 *
 * Contract:
 *  - READ ONLY. This service never writes a row, never takes a lock and never
 *    consumes a coupon: `coupon_redemptions` and `coupons.redemptions_count`
 *    belong to P3-D4, after a server-confirmed payment (D-027, point 5).
 *  - Prices come exclusively from `product_prices` for the REQUESTED currency.
 *    There is no fallback currency and no automatic conversion (D-018).
 *  - Nothing supplied by the caller — price, subtotal, discount, total or
 *    product snapshot — is ever trusted; only the identity of the cart, the
 *    currency and the coupon are inputs.
 *  - Every amount is an integer in minor units: no float, no floating division,
 *    no `round()` (D-005).
 */
final class PricingService
{
    private const BASIS_POINTS_DENOMINATOR = 10_000;

    public function __construct(
        private readonly DiscountAllocator $allocator,
    ) {}

    /**
     * @param  string  $currency  canonical ISO-like code, three uppercase letters
     * @param  CarbonImmutable|null  $at  single reference instant for the whole quote
     *
     * @throws PricingException
     */
    public function quote(
        Cart $cart,
        string $currency,
        ?Coupon $coupon = null,
        ?CarbonImmutable $at = null,
    ): PricedQuote {
        // Validates the currency format once, up front.
        $zero = Money::zero($currency);

        // One immutable instant for the whole quote: two `now()` calls could
        // straddle a coupon boundary and make the result non-deterministic.
        $at ??= CarbonImmutable::now();

        $draftLines = $this->resolveLines($cart, $currency);

        $subtotal = $zero;

        foreach ($draftLines as $line) {
            $subtotal = $subtotal->add(Money::of($line['line_subtotal_minor'], $currency));
        }

        $discountMinor = 0;
        $allocation = [];
        $snapshot = null;

        if ($coupon !== null) {
            [$discountMinor, $allocation, $snapshot] = $this->applyCoupon(
                $coupon,
                $currency,
                $at,
                $draftLines,
                $subtotal,
            );
        }

        return $this->assemble($currency, $zero, $subtotal, $draftLines, $allocation, $discountMinor, $snapshot);
    }

    /**
     * Loads every cart line with its product and the single price row that
     * matches the requested currency. Three queries, whatever the cart size.
     *
     * @return list<array{cart_item_id: int, product_id: int, name: string, slug: string, type: string, unit_price_minor: int, quantity: int, line_subtotal_minor: int}>
     *
     * @throws PricingException
     */
    private function resolveLines(Cart $cart, string $currency): array
    {
        /** @var Collection<int, CartItem> $items */
        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->with(['product', 'product.prices' => fn ($query) => $query
                ->where('currency', $currency)
                ->where('is_active', true)])
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            throw PricingException::emptyCart();
        }

        $lines = [];

        foreach ($items as $item) {
            $product = $item->product;

            // A soft-deleted product is not loaded at all: fail-closed.
            if ($product === null || $product->status !== ProductStatus::Published) {
                throw PricingException::productUnavailable($item->product_id);
            }

            $price = $product->prices->first();

            if ($price === null) {
                throw PricingException::priceUnavailable($product->id, $currency);
            }

            $lines[] = [
                'cart_item_id' => $item->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'type' => $product->type->value,
                'unit_price_minor' => $price->price_minor,
                'quantity' => $item->quantity,
                'line_subtotal_minor' => IntegerMath::multiply($price->price_minor, $item->quantity),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{cart_item_id: int, product_id: int, name: string, slug: string, type: string, unit_price_minor: int, quantity: int, line_subtotal_minor: int}>  $draftLines
     * @return array{0: int, 1: array<int, int>, 2: CouponSnapshot}
     *
     * @throws PricingException
     */
    private function applyCoupon(
        Coupon $coupon,
        string $currency,
        CarbonImmutable $at,
        array $draftLines,
        Money $cartSubtotal,
    ): array {
        if (! $coupon->is_active) {
            throw PricingException::couponInactive();
        }

        // Both boundaries are inclusive.
        if ($coupon->starts_at !== null && $at->lessThan($coupon->starts_at)) {
            throw PricingException::couponNotStarted();
        }

        if ($coupon->ends_at !== null && $at->greaterThan($coupon->ends_at)) {
            throw PricingException::couponExpired();
        }

        /** @var CouponCurrencyRule|null $rule */
        $rule = $coupon->currencyRules()->where('currency', $currency)->first();

        // A fixed-amount coupon is meaningless without an amount in this very
        // currency: amounts are never converted (D-024, point 2).
        if ($coupon->discount_type === CouponDiscountType::Fixed
            && ($rule === null || $rule->fixed_amount_minor === null)) {
            throw PricingException::couponCurrencyRuleMissing($currency);
        }

        // `min_order_minor` is a floor on the ORDER, so it is measured on the
        // whole cart subtotal — while the discount base below stays restricted
        // to the eligible lines.
        if ($rule !== null && $cartSubtotal->minor < $rule->min_order_minor) {
            throw PricingException::couponMinimumNotReached();
        }

        $eligibleProductIds = $this->eligibleProductIds($coupon, $draftLines);

        $shares = [];
        $eligibleSubtotal = 0;

        foreach ($draftLines as $line) {
            if ($eligibleProductIds !== null && ! in_array($line['product_id'], $eligibleProductIds, true)) {
                continue;
            }

            $shares[] = [
                'line_id' => $line['cart_item_id'],
                'product_id' => $line['product_id'],
                'subtotal_minor' => $line['line_subtotal_minor'],
            ];

            $eligibleSubtotal = IntegerMath::add($eligibleSubtotal, $line['line_subtotal_minor']);
        }

        if ($shares === []) {
            throw PricingException::couponNotApplicable();
        }

        $discountMinor = match ($coupon->discount_type) {
            CouponDiscountType::Percent => intdiv(
                IntegerMath::multiply($eligibleSubtotal, (int) $coupon->percent_basis_points),
                self::BASIS_POINTS_DENOMINATOR,
            ),
            CouponDiscountType::Fixed => (int) $rule?->fixed_amount_minor,
        };

        if ($rule?->max_discount_minor !== null && $discountMinor > $rule->max_discount_minor) {
            $discountMinor = $rule->max_discount_minor;
        }

        // A discount can never exceed the base it was computed on.
        if ($discountMinor > $eligibleSubtotal) {
            $discountMinor = $eligibleSubtotal;
        }

        if ($discountMinor <= 0) {
            throw PricingException::couponDiscountIsZero();
        }

        return [
            $discountMinor,
            $this->allocator->allocate($discountMinor, $shares),
            new CouponSnapshot(
                couponId: $coupon->id,
                codeSnapshot: (string) $coupon->code,
                discountTypeSnapshot: $coupon->discount_type->value,
                percentBasisPointsSnapshot: $coupon->discount_type === CouponDiscountType::Percent
                    ? (int) $coupon->percent_basis_points
                    : null,
                fixedAmountMinorSnapshot: $coupon->discount_type === CouponDiscountType::Fixed
                    ? (int) $rule?->fixed_amount_minor
                    : null,
            ),
        ];
    }

    /**
     * Product ids the coupon may discount, or NULL when the coupon is global.
     *
     * Product scope and category scope are a UNION: a line is eligible when its
     * product is listed directly OR belongs to one of the listed categories.
     *
     * @param  list<array{product_id: int, ...}>  $draftLines
     * @return list<int>|null
     */
    private function eligibleProductIds(Coupon $coupon, array $draftLines): ?array
    {
        /** @var list<int> $scopedProductIds */
        $scopedProductIds = $coupon->products()->pluck('products.id')->all();
        /** @var list<int> $scopedCategoryIds */
        $scopedCategoryIds = $coupon->categories()->pluck('categories.id')->all();

        if ($scopedProductIds === [] && $scopedCategoryIds === []) {
            return null;
        }

        $eligible = $scopedProductIds;

        if ($scopedCategoryIds !== []) {
            /** @var list<int> $byCategory */
            $byCategory = Product::query()
                ->whereIn('id', array_column($draftLines, 'product_id'))
                ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $scopedCategoryIds))
                ->pluck('id')
                ->all();

            $eligible = array_merge($eligible, $byCategory);
        }

        return array_values(array_unique($eligible));
    }

    /**
     * @param  list<array{cart_item_id: int, product_id: int, name: string, slug: string, type: string, unit_price_minor: int, quantity: int, line_subtotal_minor: int}>  $draftLines
     * @param  array<int, int>  $allocation
     */
    private function assemble(
        string $currency,
        Money $zero,
        Money $subtotal,
        array $draftLines,
        array $allocation,
        int $discountMinor,
        ?CouponSnapshot $snapshot,
    ): PricedQuote {
        $lines = [];
        $allocatedDiscount = $zero;
        $lineTotals = $zero;

        foreach ($draftLines as $line) {
            $lineDiscount = $allocation[$line['cart_item_id']] ?? 0;
            $lineTotal = IntegerMath::subtract($line['line_subtotal_minor'], $lineDiscount);

            $lines[] = new PricedLine(
                cartItemId: $line['cart_item_id'],
                productId: $line['product_id'],
                productNameSnapshot: $line['name'],
                productSlugSnapshot: $line['slug'],
                productTypeSnapshot: $line['type'],
                unitPriceMinor: $line['unit_price_minor'],
                quantity: $line['quantity'],
                lineSubtotalMinor: $line['line_subtotal_minor'],
                lineDiscountMinor: $lineDiscount,
                lineTotalMinor: $lineTotal,
            );

            $allocatedDiscount = $allocatedDiscount->add(Money::of($lineDiscount, $currency));
            $lineTotals = $lineTotals->add(Money::of($lineTotal, $currency));
        }

        // No tax policy exists in P3-D1; a future one needs its own decision.
        $tax = $zero;
        $total = $subtotal->subtract($allocatedDiscount)->add($tax);

        // Mirrors validate_order_items_consistency: the checkout must never be
        // handed a quote PostgreSQL would reject at COMMIT.
        if ($allocatedDiscount->minor !== $discountMinor) {
            throw new LogicException('Allocated line discounts do not sum to the order discount.');
        }

        if (! $total->equals($lineTotals->add($tax))) {
            throw new LogicException('Order total does not match the sum of the line totals.');
        }

        if ($total->isNegative() || $allocatedDiscount->isGreaterThan($subtotal)) {
            throw new LogicException('A quote can never be negative nor discount more than its subtotal.');
        }

        return new PricedQuote(
            currency: $currency,
            subtotalMinor: $subtotal->minor,
            discountMinor: $allocatedDiscount->minor,
            taxMinor: $tax->minor,
            totalMinor: $total->minor,
            lines: $lines,
            couponSnapshot: $snapshot,
        );
    }
}
