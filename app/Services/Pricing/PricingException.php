<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use RuntimeException;

/**
 * A cart could not be priced. The `reason` carries the closed refusal code so
 * the future checkout can map it to a validation error without parsing text.
 */
final class PricingException extends RuntimeException
{
    private function __construct(
        public readonly PricingRefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function emptyCart(): self
    {
        return new self(
            PricingRefusalReason::EmptyCart,
            'An empty cart cannot be priced: an order requires at least one line.',
        );
    }

    public static function productUnavailable(?int $productId): self
    {
        return new self(
            PricingRefusalReason::ProductUnavailable,
            'Product ['.($productId ?? 'unknown').'] is not available for sale.',
        );
    }

    public static function priceUnavailable(int $productId, string $currency): self
    {
        return new self(
            PricingRefusalReason::PriceUnavailable,
            "Product [{$productId}] has no active price in [{$currency}]: there is no currency fallback (D-018).",
        );
    }

    public static function couponInactive(): self
    {
        return new self(
            PricingRefusalReason::CouponInactive,
            'This coupon is not active.',
        );
    }

    public static function couponNotStarted(): self
    {
        return new self(
            PricingRefusalReason::CouponNotStarted,
            'This coupon is not usable yet.',
        );
    }

    public static function couponExpired(): self
    {
        return new self(
            PricingRefusalReason::CouponExpired,
            'This coupon has expired.',
        );
    }

    public static function couponCurrencyRuleMissing(string $currency): self
    {
        return new self(
            PricingRefusalReason::CouponCurrencyRuleMissing,
            "This fixed-amount coupon has no usable rule for [{$currency}]: amounts are never converted (D-024).",
        );
    }

    public static function couponMinimumNotReached(): self
    {
        return new self(
            PricingRefusalReason::CouponMinimumNotReached,
            'The cart total is below the minimum required by this coupon.',
        );
    }

    public static function couponNotApplicable(): self
    {
        return new self(
            PricingRefusalReason::CouponNotApplicable,
            'This coupon applies to none of the cart lines and is never dropped silently (D-030, Q3 = A).',
        );
    }

    public static function couponDiscountIsZero(): self
    {
        return new self(
            PricingRefusalReason::CouponDiscountIsZero,
            'This coupon would grant no discount, and an order cannot carry a coupon snapshot with a zero discount.',
        );
    }
}
