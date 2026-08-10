<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Mail\AbandonedCartReminder;
use App\Support\CartReminderConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * P6-C send path (D-056 §6, §11, §12).
 *
 * THE ORDER OF OPERATIONS IS THE SECURITY MODEL:
 *   1. short transaction: claim the attempt (pending → claimed, atomic)
 *   2. COMMIT
 *   3. re-evaluate eligibility AUTHORITATIVELY — hours may have passed since queuing
 *   4. mint the capability in memory, persist ONLY its digest (short transaction)
 *   5. provider I/O, with NO transaction open
 *   6. short transaction: finalise
 *
 * The raw capability exists solely as a local variable between steps 4 and 5. It is
 * never persisted, queued, logged, or placed in an exception, and it is not
 * reconstructible from the digest — so a crash between COMMIT and delivery destroys it
 * permanently. That is deliberate: the retry mints a NEW secret and overwrites the
 * digest, which is what makes the lost one unusable rather than merely forgotten.
 */
final class CartReminderDispatcher
{
    public function __construct(
        private readonly CartReminderService $ledger,
        private readonly CartReminderEligibility $eligibility,
    ) {}

    /**
     * Process one attempt end to end. Returns the terminal status.
     */
    public function dispatch(int $attemptId): string
    {
        $this->assertOutsideTransaction();

        $claim = $this->ledger->claim($attemptId);

        if ($claim['status'] !== 'claimed') {
            // Already claimed by another worker, or already terminal. Never send twice.
            return $claim['status'];
        }

        $cartId = (int) $claim['cart_id'];
        $verdict = $this->eligibility->evaluate($cartId);

        if ($verdict['ok'] === false) {
            // A normal outcome, recorded with an allowlisted reason and no PII.
            return $this->ledger->suppress($attemptId, $verdict['reason']);
        }

        // The capability lives here and nowhere else.
        $secret = self::mintSecret();

        $attached = $this->ledger->attachSecret($attemptId, hash('sha256', $secret));

        if ($attached !== 'claimed') {
            return $attached;
        }

        $this->assertOutsideTransaction();

        try {
            Mail::to($verdict['user']->email)->send(new AbandonedCartReminder(
                cartPublicId: (string) $verdict['cart']->public_id,
                capability: $secret,
                expiresInMinutes: CartReminderConfig::capabilityTtlMinutes(),
            ));
        } catch (Throwable $exception) {
            // The provider message may quote the recipient or the link: only a SQLSTATE
            // shaped code is ever recorded, and only when we actually have one.
            return $this->ledger->fail($attemptId, null);
        }

        return $this->ledger->complete($attemptId);
    }

    /**
     * 256 bits from a CSPRNG. Returned by value so the caller owns the only copy.
     */
    private static function mintSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash a presented capability for lookup. Separate from minting so the resume path
     * never needs the minting code, and so both sides use the same digest function.
     */
    public static function digest(#[SensitiveParameter] string $capability): string
    {
        return hash('sha256', $capability);
    }

    /**
     * Holding a PostgreSQL transaction open across an SMTP round trip pins a connection
     * for the whole provider latency and can hold locks on the ledger while the network
     * stalls. The guard refuses rather than trusting the caller (D-036).
     */
    private function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Cart reminder delivery must run outside a database transaction.');
        }
    }
}
