<?php

declare(strict_types=1);

namespace App\Services\Checkout;

/**
 * Closed set of reasons for which a checkout cannot produce an Order (D-031).
 *
 * `CartUnavailable` deliberately covers "no such cart" AND "not your cart": a
 * distinct reason would turn `carts.public_id` into an enumeration oracle.
 */
enum CheckoutRefusalReason: string
{
    case CartUnavailable = 'cart_unavailable';
    case CartExpired = 'cart_expired';
    case CartNotActive = 'cart_not_active';
    case CartEmpty = 'cart_empty';
    case CartAlreadyCheckedOut = 'cart_already_checked_out';
    case IdempotencyConflict = 'idempotency_conflict';
    case InvalidIdempotencyKey = 'invalid_idempotency_key';
    case InvalidCurrency = 'invalid_currency';
    case InvalidEmail = 'invalid_email';
    case ProductUnavailable = 'product_unavailable';
    case PriceUnavailable = 'price_unavailable';
    case CouponUnavailable = 'coupon_unavailable';
    case BundleEmpty = 'bundle_empty';
    case BundleComponentUnavailable = 'bundle_component_unavailable';
    case BundleSnapshotMismatch = 'bundle_snapshot_mismatch';
    case IntegrityFailure = 'integrity_failure';
}
