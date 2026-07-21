<?php

declare(strict_types=1);

namespace App\Support;

use OverflowException;

/**
 * Overflow-checked integer arithmetic for monetary amounts (D-005).
 *
 * PHP silently promotes an overflowing integer operation to a float, which turns
 * an exact amount in minor units into an approximation — precisely what D-005
 * forbids. Every multiplication, addition and subtraction performed on money in
 * the pricing kernel therefore goes through these guards, which refuse loudly
 * instead of degrading precision.
 *
 * `declare(strict_types=1)` is deliberate here and in the rest of the P3-D1
 * kernel: without it a float argument would be coerced to int and a fractional
 * amount would be truncated in silence.
 */
final class IntegerMath
{
    public static function multiply(int $a, int $b): int
    {
        $product = $a * $b;

        // An int * int that overflows is returned as a float by PHP.
        if (! is_int($product)) {
            throw new OverflowException('Integer overflow while multiplying monetary values.');
        }

        return $product;
    }

    public static function add(int $a, int $b): int
    {
        $sum = $a + $b;

        if (! is_int($sum)) {
            throw new OverflowException('Integer overflow while adding monetary values.');
        }

        return $sum;
    }

    public static function subtract(int $a, int $b): int
    {
        $difference = $a - $b;

        if (! is_int($difference)) {
            throw new OverflowException('Integer overflow while subtracting monetary values.');
        }

        return $difference;
    }
}
