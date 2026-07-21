<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * One priced cart line, carrying everything the future OrderService needs to
 * write an `order_items` row without touching the catalogue again (D-006).
 *
 * Every amount is an integer in minor units. The snapshots come from the
 * database, never from the caller.
 */
final readonly class PricedLine
{
    public function __construct(
        public int $cartItemId,
        public int $productId,
        public string $productNameSnapshot,
        public string $productSlugSnapshot,
        public string $productTypeSnapshot,
        public int $unitPriceMinor,
        public int $quantity,
        public int $lineSubtotalMinor,
        public int $lineDiscountMinor,
        public int $lineTotalMinor,
    ) {}
}
