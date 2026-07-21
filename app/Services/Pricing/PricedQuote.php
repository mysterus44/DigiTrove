<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * An immutable, fully resolved price for a cart at one instant.
 *
 * Guaranteed invariants, which are exactly the ones the future `orders` and
 * `order_items` rows must satisfy at COMMIT:
 *
 *   subtotalMinor = SUM(line_subtotal_minor)
 *   discountMinor = SUM(line_discount_minor)   <- deferred trigger, EXACT
 *   totalMinor    = subtotalMinor - discountMinor + taxMinor
 *   couponSnapshot !== null  <=>  discountMinor > 0
 *
 * `taxMinor` is explicitly 0: P3-D1 defines NO tax policy, claims no fiscal
 * compliance, and a future tax capability will require its own decision and
 * its own gate.
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
    ) {}
}
