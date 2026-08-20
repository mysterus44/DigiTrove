<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\Payments\NormalizedPaymentStatus;
use App\Contracts\Payments\PaymentConfirmationProvider;
use App\Contracts\Payments\ProviderPaymentVerificationRequest;
use App\Contracts\Payments\ProviderPaymentVerificationResult;
use App\Contracts\Payments\ProviderWebhookEnvelope;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Events\OrderPaid;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Payments\CinetPay\CinetPayWebhook;
use App\Payments\GeniusPay\GeniusPayWebhook;
use App\Payments\PaymentProviderFactory;
use App\Services\Payments\PaymentConfirmationRefusalReason as Reason;
use App\Support\CustomerRedemptionKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Server-side payment confirmation and OrderPaid dispatch (P3-D4/P3-D5, D-034).
 *
 * The webhook body is NEVER authoritative: a valid signature only authorises a
 * mandatory provider counter-call, whose normalized result is the sole external
 * truth. No network call ever happens inside a transaction. Money is compared as
 * integers only. A confirmed-but-inconsistent success is diverted to manual
 * review, never turned into a false `paid` nor a `failed`. OrderPaid is
 * dispatched once, after COMMIT, only on a real `pending → paid` transition.
 */
final class PaymentConfirmationService
{
    private const CINETPAY = 'cinetpay';

    private const GENIUSPAY = 'geniuspay';

    /**
     * The RAW vendor label proving a refund at the provider.
     *
     * Read raw on purpose: `refunded` maps to `NormalizedPaymentStatus::Unknown`, because
     * neither `Succeeded` nor `Failed` is true of money that changed hands and came back.
     */
    private const GENIUSPAY_REFUNDED_STATUS = 'refunded';

    /**
     * The CLOSED set of columns a webhook may locate a payment by.
     *
     * A column name reaching `where()` from a variable is an injection surface unless it can
     * only ever be one of a fixed, code-owned set. It is validated by identity against this
     * list BEFORE any query is built, and anything else raises rather than querying. Neither
     * value is caller- or provider-supplied: each is chosen by the adapter's entry point.
     *
     * `public_id` — CinetPay: our own id makes the round trip as `cpm_trans_id`.
     * `provider_payment_reference` — GeniusPay: it accepts no merchant id, so its `MTX-...`
     * reference (unique per provider by `payments_provider_reference_unique`, and immutable
     * once set by the `payments` trigger) is the only handle that comes back.
     */
    private const LOCATOR_COLUMNS = ['public_id', 'provider_payment_reference'];

    private const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __construct(
        private readonly PaymentProviderFactory $factory,
        private readonly WebhookRecordingService $recorder,
    ) {}

    public function handleCinetPayWebhook(
        ProviderWebhookEnvelope $envelope,
        ?CarbonImmutable $at = null,
    ): WebhookOutcome {
        // The counter-call must run at transaction level 0: an ambient
        // transaction would hold locks across the external latency.
        if (DB::transactionLevel() !== 0) {
            throw self::integrity();
        }

        $now = $at ?? CarbonImmutable::now();
        $provider = $this->confirmationProvider();

        $transactionId = CinetPayWebhook::transactionId($envelope);
        if ($transactionId === null || preg_match(self::UUID_PATTERN, $transactionId) !== 1) {
            throw PaymentConfirmationException::of(Reason::WebhookInvalid, 'The webhook payload is invalid.');
        }

        $filtered = CinetPayWebhook::filteredPayload($envelope);
        $payloadHash = CinetPayWebhook::payloadHash($filtered);

        $externalEventId = CinetPayWebhook::externalEventId($transactionId, $payloadHash);

        return $this->processVerifiedWebhook(
            providerName: self::CINETPAY,
            provider: $provider,
            envelope: $envelope,
            transactionId: $transactionId,
            locatorColumn: 'public_id',
            locatorValue: $transactionId,
            payloadHash: $payloadHash,
            externalEventId: $externalEventId,
            filtered: $filtered,
            eventType: $envelope->param('cpm_page_action'),
            now: $now,
            // CinetPay knows our `public_id` from the reservation onward, so a signed
            // webhook can never arrive before the payment is findable. Its unresolved
            // path is unchanged: `ignored`, 200, no retry.
            retryUnresolved: false,
        );
    }

    /**
     * The provider-agnostic half of webhook confirmation (P3-D4, generalised for GeniusPay).
     *
     * ⚠️ NOT A SECOND IMPLEMENTATION. Every adapter shares THIS body: duplicating ninety
     * lines of financial confirmation would create two versions of one truth that must stay
     * synchronised for ever. The public entry points differ only in how they EXTRACT the
     * transaction id, the payload hash and the external event id from their own wire format.
     *
     * The order is deliberately unchanged from the CinetPay-only version — transaction-level
     * guard, clock, provider resolution and extraction all happen in the caller, BEFORE this
     * method — so a malformed payload under a misconfigured driver still refuses in exactly
     * the order it always did.
     *
     * `transactionId` and `locatorValue` are deliberately SEPARATE parameters even where an
     * adapter passes the same string for both: one identifies the attempt to the PROVIDER on
     * the counter-call, the other identifies it LOCALLY. CinetPay conflates them; GeniusPay
     * cannot, and one parameter serving both roles would hide that.
     *
     * @param  array<string, string>  $filtered  the payload actually stored; never the raw body
     * @param  bool  $retryUnresolved  see {@see self::confirm()} - no default, on purpose
     */
    private function processVerifiedWebhook(
        string $providerName,
        PaymentConfirmationProvider $provider,
        ProviderWebhookEnvelope $envelope,
        string $transactionId,
        string $locatorColumn,
        string $locatorValue,
        string $payloadHash,
        string $externalEventId,
        array $filtered,
        ?string $eventType,
        CarbonImmutable $now,
        bool $retryUnresolved,
    ): WebhookOutcome {
        $recorded = $this->authenticateAndRecord(
            $providerName,
            $provider,
            $envelope,
            $payloadHash,
            $externalEventId,
            $filtered,
            $eventType,
            $now,
        );

        if ($recorded instanceof WebhookOutcome) {
            return $recorded;
        }

        $result = $this->counterCall($provider, $transactionId, $recorded->id, $now);

        $this->guarded(fn () => $this->confirm(
            $providerName,
            $locatorColumn,
            $locatorValue,
            $recorded->id,
            $result,
            $now,
            $retryUnresolved,
        ));

        return WebhookOutcome::Accepted;
    }

    /**
     * Server-side confirmation of a GeniusPay webhook (Genius Pay gate).
     *
     * The sequence mirrors {@see self::handleCinetPayWebhook()} exactly — transaction-level
     * guard, clock, provider resolution, then extraction — so the two entry points refuse in
     * the same order. Only the wire format and the locator differ.
     *
     * `$refunds` is a METHOD dependency, not a constructor one, and deliberately so: adding
     * it to the constructor would change the shape of a P3-D4 authority every existing test
     * builds, for a collaborator only one of its two entry points can ever use.
     */
    public function handleGeniusPayWebhook(
        ProviderWebhookEnvelope $envelope,
        GeniusPayRefundIntakeService $refunds,
        ?CarbonImmutable $at = null,
    ): WebhookOutcome {
        // The counter-call must run at transaction level 0: an ambient
        // transaction would hold locks across the external latency.
        if (DB::transactionLevel() !== 0) {
            throw self::integrity();
        }

        $now = $at ?? CarbonImmutable::now();
        $provider = $this->confirmationProvider();

        // Both are mandatory: the event id is the ONLY deduplication handle GeniusPay gives,
        // and the reference is the ONLY way to find the payment locally or at the provider.
        $eventId = GeniusPayWebhook::eventId($envelope);
        $reference = GeniusPayWebhook::reference($envelope);

        if ($eventId === null || $reference === null) {
            throw PaymentConfirmationException::of(Reason::WebhookInvalid, 'The webhook payload is invalid.');
        }

        $filtered = GeniusPayWebhook::filteredPayload($envelope->params);
        $payloadHash = GeniusPayWebhook::payloadHash($filtered);
        $externalEventId = GeniusPayWebhook::externalEventId($eventId);
        $eventType = GeniusPayWebhook::eventType($envelope);

        // A refund is NOT a confirmation: `refunded` normalises to `Unknown`, so it could
        // never travel the confirmation path without being silently ignored.
        if ($eventType === GeniusPayWebhook::REFUND_EVENT) {
            return $this->processRefundWebhook(
                provider: $provider,
                envelope: $envelope,
                refunds: $refunds,
                webhookEventId: $eventId,
                reference: $reference,
                payloadHash: $payloadHash,
                externalEventId: $externalEventId,
                filtered: $filtered,
                eventType: $eventType,
                now: $now,
            );
        }

        return $this->processVerifiedWebhook(
            providerName: self::GENIUSPAY,
            provider: $provider,
            envelope: $envelope,
            // GeniusPay accepts no merchant id, so the same reference plays both roles: the
            // provider handle on the counter-call and the local lookup key.
            transactionId: $reference,
            locatorColumn: 'provider_payment_reference',
            locatorValue: $reference,
            payloadHash: $payloadHash,
            externalEventId: $externalEventId,
            filtered: $filtered,
            eventType: $eventType,
            now: $now,
            // See the unresolved branch of confirm(): the reference only exists locally after
            // P3-D3's second transaction, so an early webhook must stay retryable.
            retryUnresolved: true,
        );
    }

    /**
     * Provider-observed refund intake (Genius Pay gate).
     *
     * It shares authentication, deduplication and the counter-call with the confirmation
     * path — the same body, never a copy of it — and diverges only at the last step, where
     * creating a refund replaces confirming a payment.
     *
     * @param  array<string, string>  $filtered
     */
    private function processRefundWebhook(
        PaymentConfirmationProvider $provider,
        ProviderWebhookEnvelope $envelope,
        GeniusPayRefundIntakeService $refunds,
        string $webhookEventId,
        string $reference,
        string $payloadHash,
        string $externalEventId,
        array $filtered,
        ?string $eventType,
        CarbonImmutable $now,
    ): WebhookOutcome {
        $recorded = $this->authenticateAndRecord(
            self::GENIUSPAY,
            $provider,
            $envelope,
            $payloadHash,
            $externalEventId,
            $filtered,
            $eventType,
            $now,
        );

        if ($recorded instanceof WebhookOutcome) {
            return $recorded;
        }

        $result = $this->counterCall($provider, $reference, $recorded->id, $now);

        // The body claiming a refund is not a refund. The provider's own record is the only
        // authority, exactly as it is for a payment (D-034). The RAW vendor label is read
        // here because `refunded` normalises to `Unknown` on purpose.
        if (strtolower(trim((string) $result->providerStatus)) !== self::GENIUSPAY_REFUNDED_STATUS) {
            $this->markEventIgnoredOutsideTransaction($recorded->id, $now);

            return WebhookOutcome::Accepted;
        }

        try {
            $refunds->recordTotalRefund($webhookEventId, $reference, $result->providerStatus, $now);
        } catch (PaymentConfirmationException $exception) {
            // Unresolved stays retryable and the event stays `received`; anything else is a
            // real failure and is recorded as one.
            if ($exception->reason !== Reason::PaymentUnavailable) {
                $this->markEventFailed($recorded->id, $now);
            }

            throw $exception;
        } catch (Throwable) {
            $this->markEventFailed($recorded->id, $now);

            throw self::integrity();
        }

        $this->markEventProcessedOutsideTransaction($recorded->id, $now);

        return WebhookOutcome::Accepted;
    }

    /**
     * Authenticate the webhook and record it exactly once.
     *
     * Returns the recorded event, or a {@see WebhookOutcome} when the caller must stop —
     * a rejected signature or an already-terminal replay.
     *
     * @param  array<string, string>  $filtered
     */
    private function authenticateAndRecord(
        string $providerName,
        PaymentConfirmationProvider $provider,
        ProviderWebhookEnvelope $envelope,
        string $payloadHash,
        string $externalEventId,
        array $filtered,
        ?string $eventType,
        CarbonImmutable $now,
    ): RecordedWebhook|WebhookOutcome {
        // A valid signature authorises the counter-call; it never confirms money.
        if (! $provider->verifyWebhookSignature($envelope)) {
            $this->guarded(fn () => $this->recorder->recordInvalid($providerName, $payloadHash, $now));

            return WebhookOutcome::SignatureRejected;
        }

        $recorded = $this->guarded(fn (): RecordedWebhook => $this->recorder->recordVerified(
            $providerName,
            $externalEventId,
            $payloadHash,
            $filtered,
            $eventType,
            $now,
        ));

        // An already-terminal replay is not reprocessed and touches no money.
        if ($recorded->isReplay && $recorded->isTerminal()) {
            return WebhookOutcome::Replayed;
        }

        return $recorded;
    }

    /** The mandatory provider counter-call; the webhook body is never authoritative. */
    private function counterCall(
        PaymentConfirmationProvider $provider,
        string $transactionId,
        int $eventId,
        CarbonImmutable $now,
    ): ProviderPaymentVerificationResult {
        try {
            return $provider->verifyPayment(new ProviderPaymentVerificationRequest($transactionId));
        } catch (PaymentConfirmationException $exception) {
            // No money mutated; the event is failed in its own transaction.
            $this->markEventFailed($eventId, $now);

            throw $exception;
        }
    }

    private function confirmationProvider(): PaymentConfirmationProvider
    {
        // Throws ProviderConfigurationFailure when the driver is empty/unknown
        // or the adapter is incomplete — before any HTTP request.
        return $this->factory->make();
    }

    /**
     * @param  string  $locatorColumn  one of {@see self::LOCATOR_COLUMNS}; anything else raises
     * @param  bool  $retryUnresolved  when the signed webhook names a payment we cannot find:
     *                                 `false` classifies it `ignored` (terminal, 200) - the
     *                                 historical CinetPay behaviour; `true` leaves the event
     *                                 `received` and refuses, so a provider retry reprocesses
     *                                 it. See the unresolved branch for why.
     */
    private function confirm(
        string $providerName,
        string $locatorColumn,
        string $locatorValue,
        int $eventId,
        ProviderPaymentVerificationResult $result,
        CarbonImmutable $now,
        bool $retryUnresolved,
    ): void {
        // Validated by identity against a closed, code-owned list BEFORE a query exists.
        if (! in_array($locatorColumn, self::LOCATOR_COLUMNS, true)) {
            throw self::integrity();
        }

        DB::transaction(function () use ($providerName, $locatorColumn, $locatorValue, $eventId, $result, $now, $retryUnresolved): void {
            // Locate the payment WITHOUT locking to discover its order.
            $located = Payment::query()
                ->where('provider', $providerName)
                ->where($locatorColumn, $locatorValue)
                ->first();

            if ($located === null) {
                if ($retryUnresolved) {
                    // P3-D3 initiates in two phases: the `pending` row is committed, the
                    // provider is called OUTSIDE any transaction, and the reference is
                    // persisted in a second transaction. For a provider located BY that
                    // reference, a webhook arriving inside that window finds nothing - and
                    // classifying it `ignored` would be TERMINAL: a genuinely paid order
                    // would sit at `pending` for ever, undelivered and unflagged.
                    //
                    // Leaving the event `received` keeps it non-terminal, so a redelivery
                    // reprocesses it once the reference lands.
                    //
                    // ASSUMPTION, NAMED AS ONE: this relies on GeniusPay retrying after a
                    // non-2xx response, as nearly every webhook provider does. Their public
                    // documentation does not state it. It is not confirmed - see debt #6 in
                    // HANDOFF.md, which covers the expiry job that must eventually bound
                    // events left `received` for ever should the assumption prove wrong.
                    //
                    // The rollback below discards nothing: the event row was committed by
                    // the recorder's own transaction, not this one.
                    throw PaymentConfirmationException::of(
                        Reason::PaymentUnavailable,
                        'The payment could not be resolved.',
                    );
                }

                // Anti-enumeration: never reveal the payment does not exist.
                $this->markEventIgnored($eventId, null, $now);

                return;
            }

            // Global lock order: Order first, then Payment.
            $order = Order::query()->whereKey($located->order_id)->lockForUpdate()->first();
            $payment = Payment::query()->whereKey($located->id)->lockForUpdate()->first();

            if ($order === null || $payment === null) {
                throw self::integrity();
            }

            // Idempotent terminal states: a paid order or a payment already under
            // review is left as-is, with no second dispatch.
            if ($payment->status === PaymentStatus::Succeeded && $order->status === OrderStatus::Paid) {
                $this->markEventProcessed($eventId, $payment->id, $now);

                return;
            }

            if ($payment->status === PaymentStatus::RequiresReview || $order->status === OrderStatus::PaymentReview) {
                $this->markEventProcessed($eventId, $payment->id, $now);

                return;
            }

            $payable = in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Processing], true)
                && $order->status === OrderStatus::Pending;

            if (! $payable) {
                // Local state contradicts a live confirmation.
                if ($result->normalizedStatus === NormalizedPaymentStatus::Succeeded) {
                    $this->divertToReview($order, $payment, $eventId, $now);
                } else {
                    $this->markEventIgnored($eventId, $payment->id, $now);
                }

                return;
            }

            match ($result->normalizedStatus) {
                NormalizedPaymentStatus::Succeeded => $this->applySuccess($order, $payment, $result, $eventId, $now),
                NormalizedPaymentStatus::Processing => $this->applyProcessing($payment, $eventId, $now),
                NormalizedPaymentStatus::Failed => $this->applyTerminal($payment, PaymentStatus::Failed, 'failed_at', $eventId, $now),
                NormalizedPaymentStatus::Cancelled => $this->applyTerminal($payment, PaymentStatus::Cancelled, 'cancelled_at', $eventId, $now),
                NormalizedPaymentStatus::Pending, NormalizedPaymentStatus::Unknown => $this->markEventIgnored($eventId, $payment->id, $now),
            };
        });
    }

    private function applySuccess(Order $order, Payment $payment, ProviderPaymentVerificationResult $result, int $eventId, CarbonImmutable $now): void
    {
        // Money is compared strictly, in integer minor units and currency codes.
        $moneyOk = $result->amountMinor === (int) $payment->amount_minor
            && $result->amountMinor === (int) $order->total_minor
            && $result->currency === $payment->currency
            && $result->currency === $order->currency;

        // A stored reference is never overwritten; a different one is a conflict.
        $referenceConflict = $result->providerPaymentReference !== null
            && $payment->provider_payment_reference !== null
            && $payment->provider_payment_reference !== $result->providerPaymentReference;

        if (! $moneyOk || $referenceConflict) {
            $this->divertToReview($order, $payment, $eventId, $now);

            return;
        }

        // The coupon must be consumable now; otherwise the confirmed money is
        // preserved by diverting to review, never dropped.
        if (! $this->tryConsumeCoupon($order, $now)) {
            $this->divertToReview($order, $payment, $eventId, $now);

            return;
        }

        $this->markPaymentSucceeded($payment, $result->providerPaymentReference, $now);
        $order->forceFill(['status' => OrderStatus::Paid->value, 'paid_at' => $now])->save();
        $this->markEventProcessed($eventId, $payment->id, $now);

        // Dispatched exactly once, AFTER COMMIT; discarded automatically on rollback.
        $orderId = $order->id;
        DB::afterCommit(static function () use ($orderId): void {
            event(new OrderPaid($orderId));
        });
    }

    private function divertToReview(Order $order, Payment $payment, int $eventId, CarbonImmutable $now): void
    {
        // pending|processing -> requires_review (allowed); order -> payment_review.
        $payment->forceFill([
            'status' => PaymentStatus::RequiresReview->value,
            'last_verified_at' => $now,
        ])->save();

        $order->forceFill(['status' => OrderStatus::PaymentReview->value])->save();

        $this->markEventProcessed($eventId, $payment->id, $now);
    }

    private function applyProcessing(Payment $payment, int $eventId, CarbonImmutable $now): void
    {
        if ($payment->status === PaymentStatus::Pending) {
            $fill = ['status' => PaymentStatus::Processing->value, 'last_verified_at' => $now];
            if ($payment->processing_at === null) {
                $fill['processing_at'] = $now;
            }
            $payment->forceFill($fill)->save();
        } else {
            $payment->forceFill(['last_verified_at' => $now])->save();
        }

        // Order stays pending; no coupon; no OrderPaid.
        $this->markEventProcessed($eventId, $payment->id, $now);
    }

    private function applyTerminal(Payment $payment, PaymentStatus $status, string $dateColumn, int $eventId, CarbonImmutable $now): void
    {
        // pending|processing -> failed|cancelled (allowed). Order stays pending,
        // so a further attempt remains possible (D-033). No coupon, no OrderPaid.
        $payment->forceFill([
            'status' => $status->value,
            $dateColumn => $now,
            'last_verified_at' => $now,
        ])->save();

        $this->markEventProcessed($eventId, $payment->id, $now);
    }

    private function markPaymentSucceeded(Payment $payment, ?string $reference, CarbonImmutable $now): void
    {
        // The trigger forbids pending -> succeeded directly: climb the ladder.
        if ($payment->status === PaymentStatus::Pending) {
            $fill = ['status' => PaymentStatus::Processing->value];
            if ($payment->processing_at === null) {
                $fill['processing_at'] = $now;
            }
            $payment->forceFill($fill)->save();
        }

        $fill = [
            'status' => PaymentStatus::Succeeded->value,
            'succeeded_at' => $now,
            'last_verified_at' => $now,
        ];
        if ($payment->provider_payment_reference === null && $reference !== null) {
            $fill['provider_payment_reference'] = $reference;
        }
        $payment->forceFill($fill)->save();
    }

    /**
     * Consume the order's coupon exactly once, or report that it cannot be
     * consumed (cap reached / vanished) so the caller diverts to review. Never
     * recalculates the discount: only the order's frozen snapshot is copied.
     */
    private function tryConsumeCoupon(Order $order, CarbonImmutable $now): bool
    {
        if ($order->coupon_id === null) {
            return true;
        }

        // Idempotent backstop: one redemption per order.
        if (CouponRedemption::query()->where('order_id', $order->id)->exists()) {
            return true;
        }

        $coupon = Coupon::query()->whereKey($order->coupon_id)->lockForUpdate()->first();
        if ($coupon === null) {
            return false;
        }

        if ($coupon->max_redemptions !== null && $coupon->redemptions_count >= $coupon->max_redemptions) {
            return false;
        }

        $customerKey = CustomerRedemptionKey::forOrder($order);

        if ($coupon->max_redemptions_per_customer !== null) {
            $used = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('customer_key_version', CustomerRedemptionKey::VERSION)
                ->where('customer_key_hash', $customerKey)
                ->count();

            if ($used >= $coupon->max_redemptions_per_customer) {
                return false;
            }
        }

        CouponRedemption::query()->create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'customer_key_version' => CustomerRedemptionKey::VERSION,
            'customer_key_hash' => $customerKey,
            'coupon_code_snapshot' => $order->coupon_code_snapshot,
            'discount_type_snapshot' => $order->coupon_discount_type_snapshot,
            'discount_minor' => $order->discount_minor,
            'currency' => $order->currency,
            'redeemed_at' => $now,
        ]);

        $coupon->forceFill(['redemptions_count' => $coupon->redemptions_count + 1])->save();

        return true;
    }

    private function markEventProcessed(int $eventId, ?int $paymentId, CarbonImmutable $now): void
    {
        $this->transitionEvent($eventId, WebhookProcessingStatus::Processed, ['processed_at' => $now], $paymentId);
    }

    private function markEventIgnored(int $eventId, ?int $paymentId, CarbonImmutable $now): void
    {
        // 'ignored' requires processed_at to be set (status/date coherence CHECK).
        $this->transitionEvent($eventId, WebhookProcessingStatus::Ignored, ['processed_at' => $now], $paymentId);
    }

    /**
     * `markEventProcessed`/`markEventIgnored` run INSIDE `confirm()`'s transaction and take
     * `FOR UPDATE`. The refund path has no surrounding transaction of its own, so these two
     * open one — the same shape `markEventFailed` already uses.
     */
    private function markEventProcessedOutsideTransaction(int $eventId, CarbonImmutable $now): void
    {
        $this->guarded(fn () => DB::transaction(fn () => $this->markEventProcessed($eventId, null, $now)));
    }

    private function markEventIgnoredOutsideTransaction(int $eventId, CarbonImmutable $now): void
    {
        $this->guarded(fn () => DB::transaction(fn () => $this->markEventIgnored($eventId, null, $now)));
    }

    private function markEventFailed(int $eventId, CarbonImmutable $now): void
    {
        // A dedicated transaction: no money is ever mutated on this path.
        DB::transaction(function () use ($eventId, $now): void {
            $event = PaymentWebhookEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            if ($event === null || $event->processing_status !== WebhookProcessingStatus::Received) {
                return;
            }

            $event->forceFill([
                'processing_status' => WebhookProcessingStatus::Failed->value,
                'failed_at' => $now,
                'processing_error_sanitized' => 'Provider verification could not be completed.',
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $dates
     */
    private function transitionEvent(int $eventId, WebhookProcessingStatus $status, array $dates, ?int $paymentId): void
    {
        $event = PaymentWebhookEvent::query()->whereKey($eventId)->lockForUpdate()->first();
        if ($event === null) {
            throw self::integrity();
        }

        // Idempotent: a terminal event is never transitioned again.
        if ($event->processing_status !== WebhookProcessingStatus::Received) {
            return;
        }

        $fill = ['processing_status' => $status->value, ...$dates];

        // Link the payment only after safe identification, and only once.
        if ($paymentId !== null && $event->payment_id === null && $event->signature_verified) {
            $fill['payment_id'] = $paymentId;
        }

        $event->forceFill($fill)->save();
    }

    /**
     * Run a step and sanitise any unexpected database/model error into a generic
     * IntegrityFailure; a PaymentConfirmationException passes through unchanged.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function guarded(callable $step): mixed
    {
        try {
            return $step();
        } catch (PaymentConfirmationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::integrity();
        }
    }

    private static function integrity(): PaymentConfirmationException
    {
        return PaymentConfirmationException::of(
            Reason::IntegrityFailure,
            'The payment confirmation could not be completed.',
        );
    }
}
