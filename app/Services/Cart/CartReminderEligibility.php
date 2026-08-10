<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\UserStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\Crm\CrmAdminReadService;
use App\Services\Crm\MarketingConsentStatusQuery;
use App\Support\CartReminderConfig;
use App\Support\MailTransportGuard;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * P6-C send eligibility, re-evaluated at the LAST possible moment (D-056 §6).
 *
 * Every check here was already true when the attempt was queued. They are all redone
 * because the window between queuing and sending is measured in hours: a customer can
 * withdraw consent, finish the purchase, or have their account closed in that time, and
 * a reminder sent afterwards is at best wrong and at worst unlawful.
 *
 * A refusal returns an ALLOWLISTED reason token — never a sentence, never an address,
 * never a database message — because that token is written to an audit ledger.
 */
final class CartReminderEligibility
{
    public function __construct(
        private readonly MarketingConsentStatusQuery $consent,
        private readonly CrmAdminReadService $contacts,
    ) {}

    /**
     * @return array{ok:true,user:User,cart:Cart}|array{ok:false,reason:string}
     */
    public function evaluate(int $cartId): array
    {
        // 1. Sending must be enabled AND the transport must be able to deliver. A
        //    reminder carries a live capability, so a logging mailer is a leak.
        try {
            CartReminderConfig::assertSendEnabled();
        } catch (Throwable) {
            return $this->refuse(
                MailTransportGuard::isSafe() ? 'sending_disabled' : 'mail_transport_unsafe'
            );
        }

        $cart = Cart::query()->find($cartId);

        if ($cart === null) {
            return $this->refuse('cart_not_abandoned');
        }

        // 2. The cart must STILL be abandoned. Converted is the common race and gets its
        //    own reason so the ledger records why nothing was sent.
        if ($cart->status === CartStatus::Converted) {
            return $this->refuse('cart_converted');
        }

        if ($cart->status !== CartStatus::Abandoned) {
            return $this->refuse('cart_not_abandoned');
        }

        // 3. Identity. A guest cart is structurally unaddressable: `carts` carries no
        //    e-mail column, and inventing one would be a leak, not a feature.
        $user = $cart->user_id === null ? null : User::query()->find($cart->user_id);

        if ($user === null
            || $user->trashed()
            || $user->status !== UserStatus::Active
            || $user->email_verified_at === null) {
            return $this->refuse('user_ineligible');
        }

        // 4. An order that already covers this cart makes the reminder false.
        if ($this->orderCoversCart($cart, $user)) {
            return $this->refuse('order_covers_cart');
        }

        // 5. CRM contact + CURRENT promotional consent, resolved by EXACT normalised
        //    e-mail through the existing authorities. No fuzzy match, no alias folding.
        $contact = $this->contact($user->email);

        if ($contact === null || ($contact['status'] ?? null) === 'anonymized') {
            return $this->refuse('contact_anonymized');
        }

        if (! $this->hasConsent((string) $contact['public_id'])) {
            return $this->refuse('consent_withdrawn');
        }

        return ['ok' => true, 'user' => $user, 'cart' => $cart];
    }

    /**
     * Does an acquired order already cover EVERY product in the cart?
     *
     * Deliberately strict: a cart of A+B is only covered when both are acquired. A
     * partial purchase leaves a genuine reason to remind, so it must not suppress.
     * Coverage is computed on product identity from the Commerce snapshot, never on
     * e-mail or any heuristic.
     */
    private function orderCoversCart(Cart $cart, User $user): bool
    {
        $cartProductIds = $cart->items()->pluck('product_id')->unique()->filter()->values();

        if ($cartProductIds->isEmpty()) {
            // An empty cart has nothing to remind about.
            return true;
        }

        // ACQUIRED is the repository's own predicate (P6-A1.1): paid, partially refunded
        // and refunded all mean the purchase happened. Pending, payment_review, cancelled
        // and expired are NOT purchases and must never suppress a reminder.
        $acquired = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded])
            ->pluck('id');

        if ($acquired->isEmpty()) {
            return false;
        }

        // `purchased_product_id` is the immutable Commerce snapshot (P5-A2), so coverage
        // survives a product being renamed or soft-deleted afterwards.
        $purchased = DB::table('order_items')
            ->whereIn('order_id', $acquired)
            ->pluck('purchased_product_id')
            ->filter()
            ->unique();

        // STRICT: every cart line must be covered. A cart of A+B where only A was bought
        // still has a real reason to be reminded, so a partial purchase does not suppress.
        return $cartProductIds->diff($purchased)->isEmpty();
    }

    /** @return array<string, mixed>|null */
    private function contact(string $email): ?array
    {
        try {
            return $this->contacts->findContactByExactEmail($email);
        } catch (Throwable) {
            return null;
        }
    }

    private function hasConsent(string $contactPublicId): bool
    {
        try {
            return $this->consent->hasCurrentConsent($contactPublicId);
        } catch (Throwable) {
            // Fail closed: an unreadable consent state is NOT consent.
            return false;
        }
    }

    /** @return array{ok:false,reason:string} */
    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
