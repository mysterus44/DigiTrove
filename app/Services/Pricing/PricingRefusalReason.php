<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Closed set of reasons for which a cart cannot be turned into a quote.
 *
 * Every refusal is EXPLICIT: the kernel never silently drops a coupon, never
 * falls back to another currency and never returns a partially priced quote
 * (D-030, Q3 = A).
 */
enum PricingRefusalReason: string
{
    case EmptyCart = 'empty_cart';
    case ProductUnavailable = 'product_unavailable';
    case PriceUnavailable = 'price_unavailable';
    case CouponInactive = 'coupon_inactive';
    case CouponNotStarted = 'coupon_not_started';
    case CouponExpired = 'coupon_expired';
    case CouponCurrencyRuleMissing = 'coupon_currency_rule_missing';
    case CouponMinimumNotReached = 'coupon_minimum_not_reached';
    case CouponNotApplicable = 'coupon_not_applicable';
    case CouponDiscountIsZero = 'coupon_discount_is_zero';
}
