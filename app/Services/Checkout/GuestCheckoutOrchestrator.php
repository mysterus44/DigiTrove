<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Models\Order;
use App\Services\Cart\GuestVisitorContext;
use App\Services\Payments\PaymentInitiationException;
use App\Services\Payments\PaymentInitiationService;
use RuntimeException;

/**
 * The storefront's guest checkout, which ORCHESTRATES and decides nothing.
 *
 * Every rule of substance already lives behind an authority that P3-D built and tested:
 * `OrderService` owns the cart→order transaction and the pricing refusal, and
 * `PaymentInitiationService` owns the two-phase provider call. Neither is touched here.
 * This class supplies an actor, an email and an idempotency key, then translates what
 * comes back into something a browser can act on.
 *
 * BOTH AUTHORITIES ALREADY ACCEPT A GUEST. Their signatures take `User|Visitor` and
 * `?string $guestEmail`, and `orders.customer_email` is `CITEXT NOT NULL`. Guest purchase
 * was designed for at P3-D2/D3; nothing had to be widened to make it work.
 */
final class GuestCheckoutOrchestrator
{
    /**
     * The ONLY key read from the provider's client instructions.
     *
     * `ProviderInitiationResult` describes them as ephemeral and provider-specific — a
     * USSD string, a redirect URL, a QR payload — in an untyped array. Rather than guess
     * at alternatives, this reads one agreed key and fails closed when it is missing or
     * is not a URL. Inventing a fallback would mean shipping a redirect nobody specified.
     */
    private const PAYMENT_URL_KEY = 'payment_url';

    public function __construct(
        private readonly OrderService $orders,
        private readonly GuestVisitorContext $visitors,
        private readonly GuestCheckoutSession $session,
    ) {}

    /**
     * Turn the session's cart into a pending order.
     *
     * The idempotency key is generated here and NOT persisted: `OrderService` keeps only
     * its SHA-256 digest, so a double-submitted form produces two different keys and two
     * refusals rather than two orders — the cart's own unique constraint is what makes
     * the second attempt safe.
     *
     * @throws CheckoutException translated by the caller into a user-facing refusal
     */
    public function placeOrder(string $cartPublicId, string $email): Order
    {
        $visitor = $this->visitors->forMutation();

        $order = $this->orders->checkout(
            $visitor,
            $cartPublicId,
            'XOF',
            $this->idempotencyKey(),
            $email,
        );

        // Recorded immediately: from this point the browser can resolve the order, and
        // only this browser can.
        $this->session->remember((string) $order->public_id);

        return $order;
    }

    /**
     * Start the provider payment and return the URL to send the buyer to.
     *
     * Called OUTSIDE any transaction — `PaymentInitiationService` refuses at transaction
     * level other than 0, because an ambient transaction would hold locks across the
     * provider's latency.
     *
     * @throws PaymentInitiationException when the authority refuses
     * @throws RuntimeException when the provider returned no usable redirect
     */
    public function startPayment(Order $order): string
    {
        $visitor = $this->visitors->forMutation();

        // Resolved HERE, not injected. The binding is fail-closed when `PAYMENT_DRIVER`
        // is empty, so constructor injection would make the whole checkout surface —
        // including merely DISPLAYING the form — depend on a configured provider. A cart
        // summary must not require a payment gateway to render.
        $initiated = app(PaymentInitiationService::class)->initiate(
            $visitor,
            (string) $order->public_id,
            $this->idempotencyKey(),
        );

        $url = $initiated->clientInstructions[self::PAYMENT_URL_KEY] ?? null;

        // Fail closed. A malformed instruction is not a reason to improvise a
        // destination, and it must never reach the browser as a half-built redirect.
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('The payment could not be started.');
        }

        return $url;
    }

    /**
     * A fresh opaque token per attempt, matching the authorities' pattern
     * `[A-Za-z0-9._-]{32,255}`. 256 bits from the CSPRNG; never logged, never stored raw.
     */
    private function idempotencyKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
