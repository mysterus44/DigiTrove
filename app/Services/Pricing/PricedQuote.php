<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\IntegerMath;
use App\Support\Money;
use InvalidArgumentException;

/**
 * An immutable, fully resolved price for a cart at one instant.
 *
 * The constructor enforces exactly the invariants the future `orders` and
 * `order_items` rows must satisfy at COMMIT, so a quote PostgreSQL would reject
 * cannot be built at all (P3-D1.1 / A3):
 *
 *   subtotalMinor = SUM(line_subtotal_minor)
 *   discountMinor = SUM(line_discount_minor)   <- deferred trigger, EXACT
 *   totalMinor    = subtotalMinor - discountMinor + taxMinor
 *   couponSnapshot !== null  <=>  discountMinor > 0
 *
 * `taxMinor` is explicitly 0: P3-D1 defines NO tax policy, claims no fiscal
 * compliance, and a future tax capability will require its own decision and
 * its own gate — hence the assertion rather than a silent tolerance.
 */
final readonly class PricedQuote
{
    /**
     * @param  list<PricedLine>  $lines
     */
    public function __construct(
        public string $currency,
        public int $subtotalMinor,
        public int $discountMinor,
        public int $taxMinor,
        public int $totalMinor,
        public array $lines,
        public ?CouponSnapshot $couponSnapshot,
    ) {
        // Same single currency contract as Money: three uppercase ASCII letters.
        Money::assertValidCurrency($currency);

        if ($lines === []) {
            throw new InvalidArgumentException('A quote requires at least one priced line.');
        }

        $seenCartItemIds = [];
        $subtotals = 0;
        $discounts = 0;
        $totals = 0;

        foreach ($lines as $line) {
            if (! $line instanceof PricedLine) {
                throw new InvalidArgumentException('A quote can only contain PricedLine instances.');
            }

            if (isset($seenCartItemIds[$line->cartItemId])) {
                throw new InvalidArgumentException('A quote cannot carry the same cart item twice.');
            }

            $seenCartItemIds[$line->cartItemId] = true;

            $subtotals = IntegerMath::add($subtotals, $line->lineSubtotalMinor);
            $discounts = IntegerMath::add($discounts, $line->lineDiscountMinor);
            $totals = IntegerMath::add($totals, $line->lineTotalMinor);
        }

        if ($subtotalMinor < 0 || $discountMinor < 0 || $taxMinor < 0 || $totalMinor < 0) {
            throw new InvalidArgumentException('A quote can never carry a negative amount.');
        }

        if ($taxMinor !== 0) {
            throw new InvalidArgumentException('P3-D1 defines no tax policy: taxMinor must be zero.');
        }

        if ($subtotals !== $subtotalMinor) {
            throw new InvalidArgumentException('The quote subtotal must equal the sum of its line subtotals.');
        }

        // validate_order_items_consistency compares this sum EXACTLY at COMMIT.
        if ($discounts !== $discountMinor) {
            throw new InvalidArgumentException('The quote discount must equal the sum of its line discounts.');
        }

        if (IntegerMath::add($totals, $taxMinor) !== $totalMinor) {
            throw new InvalidArgumentException('The quote total must equal the sum of its line totals plus tax.');
        }

        // orders_discount_not_above_subtotal_check + orders_total_formula_check
        if ($discountMinor > $subtotalMinor) {
            throw new InvalidArgumentException('A quote discount can never exceed its subtotal.');
        }

        if ($totalMinor !== IntegerMath::add(IntegerMath::subtract($subtotalMinor, $discountMinor), $taxMinor)) {
            throw new InvalidArgumentException('The quote total must equal subtotal minus discount plus tax.');
        }

        // orders_coupon_snapshot_consistency_check: snapshot <=> discount > 0.
        if ($couponSnapshot === null && $discountMinor > 0) {
            throw new InvalidArgumentException('A positive discount requires the coupon snapshot that produced it.');
        }

        if ($couponSnapshot !== null && $discountMinor === 0) {
            throw new InvalidArgumentException('A coupon snapshot can never accompany a zero discount.');
        }
    }
}
