<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Produces a human-quotable order number matching
 * `orders_order_number_format_check`: `DGT-YYYY-` followed by ten Crockford
 * base32 characters (I, L, O and U excluded so a number can be read aloud and
 * typed back without ambiguity).
 *
 * The suffix comes from a CSPRNG: no sequential identifier is ever exposed, so
 * an order number reveals nothing about volume.
 *
 * Deliberately NOT final and resolved through the container: order numbering is
 * a real business primitive that a later gate may need to vary (per market or
 * per year scheme), and substituting it is how the collision-retry path is
 * exercised end to end without putting a test seam in OrderService.
 */
class OrderNumberGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const SUFFIX_LENGTH = 10;

    public function generate(CarbonImmutable $at): string
    {
        $suffix = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::SUFFIX_LENGTH; $i++) {
            $suffix .= self::ALPHABET[random_int(0, $max)];
        }

        return sprintf('DGT-%s-%s', $at->format('Y'), $suffix);
    }
}
