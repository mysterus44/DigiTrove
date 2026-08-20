<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Delivery\RefundCompletionService;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use App\Support\PostgresConstraintViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates the `refunds` row a provider-observed refund needs, then hands it to the
 * existing completion chain (Genius Pay gate).
 *
 * ⚠️ THE MISSING LINK, AND ONLY THAT. Before this gate nothing in the repository ever
 * created a `refunds` row, so `RefundCompletionService` — which only ever FINALISES an
 * existing one — had no caller. This service closes that gap and stops there:
 *
 *     webhook payment.refunded
 *       -> provider counter-call            (the body is never authoritative)
 *       -> THIS SERVICE creates `refunds` in `pending`
 *       -> RefundCompletionService::completeSucceededRefund()   UNCHANGED
 *       -> RefundSucceeded -> ProcessAffiliateRefundReversal    UNCHANGED
 *
 * ⚠️ IT NEVER FINALISES ANYTHING ITSELF. Writing `status = 'succeeded'` here would be two
 * lines shorter and would bypass `RefundCompletionService` entirely — so `RefundSucceeded`
 * would never fire, and the P6-D3/D4 compensation engine would stay dormant WITH NO ERROR
 * ANYWHERE. That is the same failure shape as P7's dead redirect table, applied to money.
 * The invariant is one-directional and permanent: creation lives here, finalisation lives
 * there, and the two contracts are never merged.
 *
 * ⚠️ TOTAL REFUNDS ONLY. GeniusPay documents no refunded-amount field, on the webhook or on
 * `GET /payments/{reference}`. Every `payment.refunded` is therefore treated as a refund of
 * the full captured amount. A partial refund the provider does not expose is undetectable
 * from DigiTrove at any level of effort — a provider limitation, not an adapter defect.
 * Tracked as debt #5 in `HANDOFF.md` and documented in `GENIUSPAY_SETUP.md`.
 */
final class GeniusPayRefundIntakeService
{
    private const PROVIDER = 'geniuspay';

    public function __construct(private readonly RefundCompletionService $completion) {}

    /**
     * Record a provider-confirmed total refund and complete it.
     *
     * @param  string  $webhookEventId  the provider's own event id; the ONLY idempotency
     *                                  source, hashed deterministically — never random, so a
     *                                  redelivery lands on the same row instead of a second
     *                                  refund.
     * @param  string  $reference  the `MTX-…` payment reference
     */
    public function recordTotalRefund(
        string $webhookEventId,
        string $reference,
        ?string $providerStatus,
        CarbonImmutable $now,
    ): void {
        // `RefundCompletionService` opens its own transaction and dispatches after COMMIT;
        // an ambient transaction would swallow that boundary.
        if (DB::transactionLevel() !== 0) {
            throw PaymentConfirmationException::of(
                Reason::IntegrityFailure,
                'The refund could not be recorded.',
            );
        }

        $payment = Payment::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_payment_reference', $reference)
            ->first();

        // Unresolved is RETRYABLE, not terminal, and for a second reason beyond the P3-D3
        // window: GeniusPay may deliver `payment.refunded` before — or instead of — the
        // `payment.completed` that would have moved the payment to `succeeded`. The schema
        // refuses a refund against a non-succeeded payment, so refusing here lets a retry
        // succeed once the confirmation lands, rather than losing the refund for ever.
        if ($payment === null || $payment->status !== PaymentStatus::Succeeded) {
            throw PaymentConfirmationException::of(
                Reason::PaymentUnavailable,
                'The payment could not be resolved.',
            );
        }

        $captured = (int) $payment->amount_minor;

        // Already fully refunded: a distinct event id for the same refund is a no-op, not a
        // second refund. Without this the cumulative-cap trigger would refuse it with a raw
        // 23514 — correct, but as a 500 rather than an idempotent success.
        $succeededSum = (int) Refund::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::Succeeded->value)
            ->sum('amount_minor');

        if ($succeededSum >= $captured) {
            return;
        }

        $refundId = $this->resolveOrCreatePendingRefund($payment, $webhookEventId, $captured, $now);

        // The provider exposes no dedicated refund reference, so the event id — unique and
        // stable on their side — is what fills `refunds.provider_refund_reference`, which is
        // unique per provider by `refunds_provider_reference_unique`.
        $this->completion->completeSucceededRefund($refundId, $webhookEventId, $providerStatus, $now);
    }

    /**
     * The deterministic idempotency key, in the exact shape the schema demands
     * (`refunds_idempotency_hash_format_check`, `^[0-9a-f]{64}$`).
     *
     * The raw event id is never persisted as the key: only its digest is, exactly as
     * P3-D3 does with the initiation idempotency key.
     */
    private function idempotencyKeyHash(string $webhookEventId): string
    {
        return hash('sha256', self::PROVIDER."\nrefund\n".$webhookEventId);
    }

    private function resolveOrCreatePendingRefund(Payment $payment, string $webhookEventId, int $amountMinor, CarbonImmutable $now): int
    {
        $keyHash = $this->idempotencyKeyHash($webhookEventId);

        $existing = Refund::query()->where('idempotency_key_hash', $keyHash)->first();
        if ($existing !== null) {
            // A replay of the same event. The row belonging to another payment would mean a
            // digest collision on a code-owned input — refuse rather than touch the wrong money.
            if ((int) $existing->payment_id !== (int) $payment->id) {
                throw PaymentConfirmationException::of(
                    Reason::IntegrityFailure,
                    'The refund could not be recorded.',
                );
            }

            return (int) $existing->id;
        }

        try {
            $refund = Refund::query()->create([
                'public_id' => (string) Str::uuid(),
                'payment_id' => $payment->id,
                // The schema requires provider and currency to match the payment exactly.
                'provider' => self::PROVIDER,
                'idempotency_key_hash' => $keyHash,
                'amount_minor' => $amountMinor,
                'currency' => $payment->currency,
                'status' => RefundStatus::Pending->value,
                'reason_code' => 'provider_refund',
                'initiated_by_user_id' => null,
                'requested_at' => $now,
            ]);
        } catch (Throwable $exception) {
            // A concurrent delivery of the same event won the race. Classified by SQLSTATE
            // plus exact constraint name — never by matching a message.
            if (PostgresConstraintViolation::isUniqueViolationOf($exception, 'refunds_idempotency_key_hash_unique')) {
                return (int) Refund::query()->where('idempotency_key_hash', $keyHash)->firstOrFail()->id;
            }

            throw $exception;
        }

        return (int) $refund->id;
    }
}
