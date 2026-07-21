<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\ProductType;
use App\Support\IntegerMath;
use InvalidArgumentException;

/**
 * One priced cart line, carrying everything the future OrderService needs to
 * write an `order_items` row without touching the catalogue again (D-006).
 *
 * Every amount is an integer in minor units. The snapshots come from the
 * database, never from the caller.
 *
 * The constructor enforces the same arithmetic the `order_items` CHECK
 * constraints enforce at INSERT time, so an inconsistent line cannot be built
 * at all (P3-D1.1 / A3) — not even by a future consumer bypassing the service.
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
    ) {
        if ($cartItemId < 1) {
            throw new InvalidArgumentException('A priced line requires a positive cart item id.');
        }

        if ($productId < 1) {
            throw new InvalidArgumentException('A priced line requires a positive product id.');
        }

        self::assertNotBlank($productNameSnapshot, 'product name snapshot');
        self::assertNotBlank($productSlugSnapshot, 'product slug snapshot');
        self::assertNotBlank($productTypeSnapshot, 'product type snapshot');

        // Mirrors order_items_product_type_check.
        if (ProductType::tryFrom($productTypeSnapshot) === null) {
            throw new InvalidArgumentException('A priced line requires a known product type snapshot.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('A priced line requires a quantity of at least one.');
        }

        if ($unitPriceMinor < 0 || $lineSubtotalMinor < 0 || $lineDiscountMinor < 0 || $lineTotalMinor < 0) {
            throw new InvalidArgumentException('A priced line can never carry a negative amount.');
        }

        // order_items_line_subtotal_formula_check
        if ($lineSubtotalMinor !== IntegerMath::multiply($unitPriceMinor, $quantity)) {
            throw new InvalidArgumentException('A line subtotal must equal the unit price times the quantity.');
        }

        // order_items_line_discount_not_above_subtotal_check
        if ($lineDiscountMinor > $lineSubtotalMinor) {
            throw new InvalidArgumentException('A line discount can never exceed its subtotal.');
        }

        // order_items_line_total_formula_check
        if ($lineTotalMinor !== IntegerMath::subtract($lineSubtotalMinor, $lineDiscountMinor)) {
            throw new InvalidArgumentException('A line total must equal its subtotal minus its discount.');
        }
    }

    private static function assertNotBlank(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("A priced line requires a non blank {$label}.");
        }
    }
}
