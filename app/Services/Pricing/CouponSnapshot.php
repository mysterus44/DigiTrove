<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * The coupon exactly as it must be frozen on `orders` at checkout.
 *
 * Fields mirror the `orders` columns one to one, so P3-D2 has nothing to
 * recompute: `orders_coupon_snapshot_consistency_check` accepts a `percent`
 * snapshot only with basis points, and a `fixed` snapshot only with an amount.
 *
 * This value never reaches the database in P3-D1: no order exists in this gate.
 */
final readonly class CouponSnapshot
{
    public function __construct(
        public int $couponId,
        public string $codeSnapshot,
        public string $discountTypeSnapshot,
        public ?int $percentBasisPointsSnapshot,
        public ?int $fixedAmountMinorSnapshot,
    ) {}
}
