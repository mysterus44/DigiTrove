<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use Illuminate\Support\Facades\Session;

/**
 * Which order this browser is allowed to look at.
 *
 * `orders.public_id` is a UUID, not a secret: it is opaque, but nothing stops it being
 * copied, logged by an intermediary or pasted into a support ticket. So possessing one
 * is NOT authorisation here. The browser may only resolve the order whose `public_id`
 * this session put down at checkout, and anything else — a real order belonging to
 * someone else, or an id that never existed — produces the SAME flat 404. A distinct
 * response would turn the route into an oracle answering "does this order exist?".
 *
 * `order_number` never travels in a URL. It is human-readable by design (Crockford
 * base32, `DGT-2026-…`), which makes it exactly the wrong thing to put in a path.
 *
 * OUT OF SCOPE, DELIBERATELY: there is no way to reopen an order later from an email
 * link. Once the session is gone the order is unreachable from the storefront. That is
 * an MVP scope decision, not an oversight — a durable link would need its own hashed
 * capability, like P6-C's cart resume, and that is a gate of its own.
 */
final class GuestCheckoutSession
{
    public const SESSION_KEY = 'checkout.order_public_id';

    public function remember(string $orderPublicId): void
    {
        Session::put(self::SESSION_KEY, $orderPublicId);
    }

    public function currentPublicId(): ?string
    {
        $value = Session::get(self::SESSION_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** True only when the session itself put this exact id down. */
    public function owns(string $orderPublicId): bool
    {
        $current = $this->currentPublicId();

        return $current !== null && hash_equals($current, $orderPublicId);
    }

    public function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
