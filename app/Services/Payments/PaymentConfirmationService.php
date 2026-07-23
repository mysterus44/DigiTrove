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

        // A valid signature authorises the counter-call; it never confirms money.
        if (! $provider->verifyWebhookSignature($envelope)) {
            $this->guarded(fn () => $this->recorder->recordInvalid(self::CINETPAY, $payloadHash, $now));

            return WebhookOutcome::SignatureRejected;
        }

        $externalEventId = CinetPayWebhook::externalEventId($transactionId, $payloadHash);
        $recorded = $this->guarded(fn (): RecordedWebhook => $this->recorder->recordVerified(
            self::CINETPAY,
            $externalEventId,
            $payloadHash,
            $filtered,
            $envelope->param('cpm_page_action'),
            $now,
        ));

        // An already-terminal replay is not reprocessed and touches no money.
        if ($recorded->isReplay && $recorded->isTerminal()) {
            return WebhookOutcome::Replayed;
        }

        try {
            $result = $provider->verifyPayment(new ProviderPaymentVerificationRequest($transactionId));
        } catch (PaymentConfirmationException $exception) {
            // No money mutated; the event is failed in its own transaction.
            $this->markEventFailed($recorded->id, $now);

            throw $exception;
        }

        $this->guarded(fn () => $this->confirm($recorded->id, $transactionId, $result, $now));

        return WebhookOutcome::Accepted;
    }

    private function confirmationProvider(): PaymentConfirmationProvider
    {
        // Throws ProviderConfigurationFailure when the driver is empty/unknown
        // or the adapter is incomplete — before any HTTP request.
        return $this->factory->make();
    }

    private function confirm(int $eventId, string $transactionId, ProviderPaymentVerificationResult $result, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($eventId, $transactionId, $result, $now): void {
            // Locate the payment WITHOUT locking to discover its order.
            $located = Payment::query()
                ->where('provider', self::CINETPAY)
                ->where('public_id', $transactionId)
                ->first();

            if ($located === null) {
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
