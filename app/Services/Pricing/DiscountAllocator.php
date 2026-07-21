<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\IntegerMath;
use InvalidArgumentException;
use LogicException;

/**
 * Splits an order-level discount across the eligible lines using the largest
 * remainder method — Hamilton (D-030, Q3 = A).
 *
 * The deferred constraint trigger `validate_order_items_consistency` checks at
 * COMMIT that `SUM(order_items.line_discount_minor) = orders.discount_minor`
 * EXACTLY. A proportional split therefore cannot simply round each line: the
 * integer remainder has to be distributed, one minor unit at a time, in a
 * deterministic order.
 *
 * Tie-break contract, in order: residue descending, then `product_id` ascending,
 * then line id ascending. The result never depends on the order in which the
 * lines were loaded.
 *
 * No float, no floating division, no `round()`.
 */
final class DiscountAllocator
{
    /**
     * @param  list<array{line_id: int, product_id: int, subtotal_minor: int}>  $shares
     * @return array<int, int> discount in minor units, keyed by line id
     */
    public function allocate(int $discountMinor, array $shares): array
    {
        if ($discountMinor < 0) {
            throw new InvalidArgumentException('A discount cannot be negative.');
        }

        $eligibleSubtotal = 0;
        $seenLineIds = [];

        foreach ($shares as $share) {
            if ($share['line_id'] < 1) {
                throw new InvalidArgumentException('A share requires a positive line id.');
            }

            // Without this guard two shares sharing a line id collapse onto the
            // same array key and the allocation silently sums to LESS than the
            // requested discount (P3-D1.1 / A4).
            if (isset($seenLineIds[$share['line_id']])) {
                throw new InvalidArgumentException('A line id cannot appear twice in an allocation.');
            }

            $seenLineIds[$share['line_id']] = true;

            if ($share['product_id'] < 1) {
                throw new InvalidArgumentException('A share requires a positive product id.');
            }

            if ($share['subtotal_minor'] < 0) {
                throw new InvalidArgumentException('A line subtotal cannot be negative.');
            }

            $eligibleSubtotal = IntegerMath::add($eligibleSubtotal, $share['subtotal_minor']);
        }

        if ($discountMinor > $eligibleSubtotal) {
            throw new InvalidArgumentException(
                'A discount cannot exceed the eligible subtotal it is computed on.'
            );
        }

        $allocation = [];

        foreach ($shares as $share) {
            $allocation[$share['line_id']] = 0;
        }

        if ($discountMinor === 0) {
            return $allocation;
        }

        // Reachable only when 0 < discount <= eligible subtotal, so the divisor
        // below is strictly positive.
        $residues = [];
        $allocated = 0;

        foreach ($shares as $share) {
            $numerator = IntegerMath::multiply($discountMinor, $share['subtotal_minor']);
            $base = intdiv($numerator, $eligibleSubtotal);

            $allocation[$share['line_id']] = $base;
            $allocated = IntegerMath::add($allocated, $base);

            $residues[] = [
                'line_id' => $share['line_id'],
                'product_id' => $share['product_id'],
                'subtotal_minor' => $share['subtotal_minor'],
                'residue' => $numerator % $eligibleSubtotal,
            ];
        }

        usort($residues, static fn (array $a, array $b): int => [$b['residue'], $a['product_id'], $a['line_id']]
            <=> [$a['residue'], $b['product_id'], $b['line_id']]);

        $remaining = IntegerMath::subtract($discountMinor, $allocated);

        foreach ($residues as $row) {
            if ($remaining === 0) {
                break;
            }

            // Defensive: a line never absorbs more than its own subtotal, so a
            // free line can never end up with a negative total.
            if ($allocation[$row['line_id']] >= $row['subtotal_minor']) {
                continue;
            }

            $allocation[$row['line_id']]++;
            $remaining--;
        }

        if ($remaining !== 0) {
            throw new LogicException('Hamilton allocation failed to distribute the whole discount.');
        }

        return $allocation;
    }
}
