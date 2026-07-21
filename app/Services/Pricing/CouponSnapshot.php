<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\CouponDiscountType;
use InvalidArgumentException;

/**
 * The coupon exactly as it must be frozen on `orders` at checkout.
 *
 * Fields mirror the `orders` columns one to one, so P3-D2 has nothing to
 * recompute. The constructor enforces the very branches of
 * `orders_coupon_snapshot_consistency_check` (P3-D1.1 / A3): a `percent`
 * snapshot carries basis points and no amount, a `fixed` snapshot carries a
 * strictly positive amount and no basis points.
 *
 * There is deliberately NO currency here: `orders` keeps the currency once, on
 * the order itself. This value never reaches the database in P3-D1 — no order
 * exists in this gate.
 */
final readonly class CouponSnapshot
{
    private const MAX_BASIS_POINTS = 10_000;

    public function __construct(
        public int $couponId,
        public string $codeSnapshot,
        public string $discountTypeSnapshot,
        public ?int $percentBasisPointsSnapshot,
        public ?int $fixedAmountMinorSnapshot,
    ) {
        if ($couponId < 1) {
            throw new InvalidArgumentException('A coupon snapshot requires a positive coupon id.');
        }

        if (trim($codeSnapshot) === '') {
            throw new InvalidArgumentException('A coupon snapshot requires a non blank code.');
        }

        $type = CouponDiscountType::tryFrom($discountTypeSnapshot);

        if ($type === null) {
            throw new InvalidArgumentException('A coupon snapshot requires a known discount type.');
        }

        match ($type) {
            CouponDiscountType::Percent => $this->assertPercentShape(),
            CouponDiscountType::Fixed => $this->assertFixedShape(),
        };
    }

    private function assertPercentShape(): void
    {
        if ($this->percentBasisPointsSnapshot === null
            || $this->percentBasisPointsSnapshot < 1
            || $this->percentBasisPointsSnapshot > self::MAX_BASIS_POINTS) {
            throw new InvalidArgumentException(
                'A percent coupon snapshot requires basis points between 1 and '.self::MAX_BASIS_POINTS.'.'
            );
        }

        if ($this->fixedAmountMinorSnapshot !== null) {
            throw new InvalidArgumentException('A percent coupon snapshot can never carry a fixed amount.');
        }
    }

    private function assertFixedShape(): void
    {
        if ($this->fixedAmountMinorSnapshot === null || $this->fixedAmountMinorSnapshot < 1) {
            throw new InvalidArgumentException(
                'A fixed coupon snapshot requires a strictly positive amount in minor units.'
            );
        }

        if ($this->percentBasisPointsSnapshot !== null) {
            throw new InvalidArgumentException('A fixed coupon snapshot can never carry basis points.');
        }
    }
}
