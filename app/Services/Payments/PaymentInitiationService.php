<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\ProviderInitiationRequest;
use App\Contracts\Payments\ProviderInitiationResult;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visitor;
use App\Support\PostgresConstraintViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Initiates a payment attempt in two phases (P3-D3, D-033).
 *
 *   1. Reserve a `pending` `payments` row in a transaction, then COMMIT.
 *   2. Call the provider OUTSIDE any transaction (no lock is held across the
 *      external latency).
 *   3. Finalise the provider reference in a second transaction.
 *
 * The source of truth is `orders` only — never the cart, its lines or the
 * catalogue. No amount, currency or email supplied by the caller is trusted:
 * the signature does not even expose them.
 *
 * P3-D3 confirms nothing: the order stays `pending`, no coupon is consumed, no
 * webhook, event, job, refund or download grant is produced.
 */
final class PaymentInitiationService
{
    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9._-]{32,255}\z/';

    private const PROVIDER_NAME_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,31}\z/';

    /** Statuses that count as a live, still-running attempt (D-033). */
    private const LIVE_STATUSES = ['pending', 'processing'];

    public function __construct(
        private readonly PaymentProvider $provider,
    ) {}

    /**
     * @param  string  $idempotencyKey  raw, opaque, never stored nor logged
     *
     * @throws PaymentInitiationException
     */
    public function initiate(
        User|Visitor $actor,
        string $orderPublicId,
        #[SensitiveParameter] string $idempotencyKey,
        ?CarbonImmutable $at = null,
    ): InitiatedPayment {
        // The provider MUST be reachable only at transaction level 0 (D-033): an
        // ambient transaction would hold locks across the external latency, so
        // it is refused before any read or write.
        if (DB::transactionLevel() !== 0) {
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::IntegrityFailure,
                'The payment could not be initiated.',
            );
        }

        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::InvalidIdempotencyKey,
                'The idempotency key must be an opaque high-entropy token.',
            );
        }

        $providerName = $this->resolveProviderName();

        // Only the digest ever leaves this method.
        $digest = hash('sha256', $idempotencyKey);
        $now = $at ?? CarbonImmutable::now();

        try {
            // Phase 1: reserve (or resolve a replay of) the attempt and COMMIT.
            [$paymentId, $providerRequest] = $this->reserve($actor, $orderPublicId, $providerName, $digest, $now);

            $payment = Payment::query()->findOrFail($paymentId);

            // An attempt that must not be resumed (order no longer payable) is
            // returned as-is: the provider is not called.
            if ($providerRequest === null) {
                return $this->toResult($payment, null);
            }

            // Phase 2: OUTSIDE any transaction.
            $result = $this->callProvider($providerRequest);

            // Phase 3: finalise the reference on the same row.
            $this->finalise($payment, $result);

            return $this->toResult($payment->fresh() ?? $payment, $result);
        } catch (PaymentInitiationException $exception) {
            // Business refusals and already-sanitised provider failures pass
            // through unchanged.
            throw $exception;
        } catch (Throwable) {
            // Any unexpected database or model error is sanitised: no SQLSTATE,
            // SQL, constraint name or trigger message ever reaches the caller.
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::IntegrityFailure,
                'The payment could not be initiated.',
            );
        }
    }

    /**
     * @throws PaymentInitiationException
     */
    private function resolveProviderName(): string
    {
        try {
            $providerName = $this->provider->name();
        } catch (Throwable) {
            // A misbehaving adapter (e.g. a name accessor that reads a broken
            // config) is sanitised, never surfaced.
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::ProviderUnavailable,
                'The payment provider is currently unavailable.',
            );
        }

        if (preg_match(self::PROVIDER_NAME_PATTERN, $providerName) !== 1) {
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::InvalidProvider,
                'The configured payment provider is invalid.',
            );
        }

        return $providerName;
    }

    /**
     * @return array{0: int, 1: ProviderInitiationRequest|null} payment id, and
     *                                                          the provider request when a provider call is needed
     *
     * @throws PaymentInitiationException
     */
    private function reserve(
        User|Visitor $actor,
        string $orderPublicId,
        string $providerName,
        string $digest,
        CarbonImmutable $now,
    ): array {
        return DB::transaction(function () use ($actor, $orderPublicId, $providerName, $digest, $now): array {
            $order = Order::query()->where('public_id', $orderPublicId)->lockForUpdate()->first();

            if ($order === null || ! $this->owns($actor, $order)) {
                throw PaymentInitiationException::orderUnavailable();
            }

            // Replay is resolved before any eligibility rule: a committed
            // attempt for this digest is the normal state of a first call.
            $existing = Payment::query()
                ->where('idempotency_key_hash', $digest)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->resolveReplay($existing, $order, $providerName, $now);
            }

            $this->assertPayable($order, $now);
            $this->assertNoLiveAttempt($order);

            $attemptNumber = (int) Payment::query()->where('order_id', $order->id)->max('attempt_number') + 1;

            return $this->createAttempt($order, $providerName, $digest, $attemptNumber, $now);
        });
    }

    private function owns(User|Visitor $actor, Order $order): bool
    {
        return $actor instanceof User
            ? $order->user_id !== null && $order->user_id === $actor->id
            : $order->user_id === null && $order->visitor_id !== null && $order->visitor_id === $actor->id;
    }

    /**
     * @throws PaymentInitiationException
     */
    private function assertPayable(Order $order, CarbonImmutable $now): void
    {
        // A free order never carries a payment (the DB trigger enforces this
        // too); it reaches `paid` through the free flow in P3-D4.
        if ($order->total_minor === 0) {
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::FreeOrder,
                'A free order cannot be paid.',
            );
        }

        if ($order->status !== OrderStatus::Pending) {
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::OrderNotPayable,
                'This order can no longer be paid.',
            );
        }

        if ($this->isExpired($order, $now)) {
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::OrderExpired,
                'This order has expired.',
            );
        }
    }

    /**
     * @throws PaymentInitiationException
     */
    private function assertNoLiveAttempt(Order $order): void
    {
        $hasLive = Payment::query()
            ->where('order_id', $order->id)
            ->whereIn('status', self::LIVE_STATUSES)
            ->exists();

        if ($hasLive) {
            // A new key cannot open a second live session; the client must
            // replay the original key (D-033).
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::PaymentAlreadyInProgress,
                'A payment attempt is already in progress for this order.',
            );
        }
    }

    /**
     * @return array{0: int, 1: ProviderInitiationRequest|null}
     *
     * @throws PaymentInitiationException
     */
    private function createAttempt(
        Order $order,
        string $providerName,
        string $digest,
        int $attemptNumber,
        CarbonImmutable $now,
    ): array {
        try {
            // A 23505 would abort the whole transaction; the nested transaction
            // issues a real SAVEPOINT so the outer one stays usable.
            $payment = DB::transaction(fn (): Payment => Payment::query()->create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'provider' => $providerName,
                'provider_payment_reference' => null,
                'idempotency_key_hash' => $digest,
                'attempt_number' => $attemptNumber,
                'amount_minor' => $order->total_minor,
                'currency' => $order->currency,
                'status' => PaymentStatus::Pending,
                'initiated_at' => $now,
            ]));
        } catch (Throwable $exception) {
            return $this->recoverFromInsertFailure($exception, $order, $providerName, $digest, $now);
        }

        return [$payment->id, $this->buildProviderRequest($payment, $order)];
    }

    /**
     * A concurrent insert may have taken the digest between our lookup and our
     * insert. On the exact idempotency 23505 we re-read and replay; every other
     * violation is a distinct integrity failure.
     *
     * @return array{0: int, 1: ProviderInitiationRequest|null}
     *
     * @throws PaymentInitiationException
     */
    private function recoverFromInsertFailure(
        Throwable $exception,
        Order $order,
        string $providerName,
        string $digest,
        CarbonImmutable $now,
    ): array {
        if (PostgresConstraintViolation::isUniqueViolationOf($exception, 'payments_idempotency_key_hash_unique')) {
            $existing = Payment::query()->where('idempotency_key_hash', $digest)->first();

            if ($existing !== null) {
                return $this->resolveReplay($existing, $order, $providerName, $now);
            }
        }

        // payments_order_id_attempt_number_unique and anything else: the order
        // lock makes an attempt-number race impossible between two conforming
        // calls, so this signals a broken invariant.
        throw PaymentInitiationException::of(
            PaymentInitiationRefusalReason::IntegrityFailure,
            'The payment could not be initiated.',
        );
    }

    /**
     * @return array{0: int, 1: ProviderInitiationRequest|null}
     *
     * @throws PaymentInitiationException
     */
    private function resolveReplay(Payment $existing, Order $order, string $providerName, CarbonImmutable $now): array
    {
        if ($existing->order_id !== $order->id || $existing->provider !== $providerName) {
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::IdempotencyConflict,
                'This idempotency key was already used for a different payment.',
            );
        }

        // Resume a live attempt whenever the order is still payable, EVEN IF a
        // reference is already stored: re-calling the provider with the same
        // public id (idempotent by contract) is how a client recovers ephemeral
        // instructions lost to a crash after finalisation (D-033). Finalise then
        // stays idempotent on an identical reference and refuses a different one.
        // Expiry uses the injected instant only: expired iff now >= expires_at.
        $resumable = in_array($existing->status->value, self::LIVE_STATUSES, true)
            && $order->status === OrderStatus::Pending
            && $order->total_minor > 0
            && ! $this->isExpired($order, $now);

        return [$existing->id, $resumable ? $this->buildProviderRequest($existing, $order) : null];
    }

    private function isExpired(Order $order, CarbonImmutable $now): bool
    {
        // Single expiry predicate, shared by the initial call and every replay:
        // never reads the wall clock.
        return $order->expires_at !== null && $now->greaterThanOrEqualTo($order->expires_at);
    }

    private function buildProviderRequest(Payment $payment, Order $order): ProviderInitiationRequest
    {
        return new ProviderInitiationRequest(
            paymentPublicId: (string) $payment->public_id,
            orderPublicId: (string) $order->public_id,
            amountMinor: (int) $order->total_minor,
            currency: (string) $order->currency,
            customerEmail: (string) $order->customer_email,
        );
    }

    /**
     * @throws PaymentInitiationException
     */
    private function callProvider(ProviderInitiationRequest $request): ProviderInitiationResult
    {
        try {
            $result = $this->provider->initiate($request);
        } catch (Throwable) {
            // A timeout is ambiguous: the provider may have accepted the
            // request, so the attempt stays `pending` for a later resume. No
            // provider detail is surfaced.
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::ProviderUnavailable,
                'The payment provider is currently unavailable.',
            );
        }

        $this->assertValidResult($result);

        return $result;
    }

    /**
     * @throws PaymentInitiationException
     */
    private function assertValidResult(ProviderInitiationResult $result): void
    {
        $reference = trim($result->providerPaymentReference);

        $valid = $reference !== ''
            && mb_strlen($reference) <= 255
            && $this->withinOptionalLength($result->providerStatus, 100)
            && $this->withinOptionalLength($result->providerMethod, 64);

        if (! $valid) {
            throw PaymentInitiationException::of(
                PaymentInitiationRefusalReason::ProviderProtocolFailure,
                'The payment provider returned an invalid response.',
            );
        }
    }

    private function withinOptionalLength(?string $value, int $max): bool
    {
        if ($value === null) {
            return true;
        }

        return trim($value) !== '' && mb_strlen($value) <= $max;
    }

    /**
     * @throws PaymentInitiationException
     */
    private function finalise(Payment $payment, ProviderInitiationResult $result): void
    {
        $reference = trim($result->providerPaymentReference);

        DB::transaction(function () use ($payment, $result, $reference): void {
            // Order first, then Payment (global lock order).
            Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();
            $fresh = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($fresh === null) {
                throw PaymentInitiationException::of(
                    PaymentInitiationRefusalReason::IntegrityFailure,
                    'The payment could not be finalised.',
                );
            }

            if ($fresh->provider_payment_reference !== null) {
                // Already set: idempotent when identical, refused otherwise. The
                // stored value is never overwritten.
                if ($fresh->provider_payment_reference !== $reference) {
                    throw PaymentInitiationException::of(
                        PaymentInitiationRefusalReason::ProviderReferenceConflict,
                        'This payment already has a different provider reference.',
                    );
                }

                return;
            }

            // A duplicate reference across payments (23505 on
            // payments_provider_reference_unique) or any other database error is
            // caught by initiate()'s outer handler and sanitised to
            // IntegrityFailure — no constraint name or SQLSTATE is ever surfaced.
            $fresh->forceFill([
                'provider_payment_reference' => $reference,
                'provider_status' => $result->providerStatus,
                'provider_method' => $result->providerMethod,
            ])->save();
        });
    }

    private function toResult(Payment $payment, ?ProviderInitiationResult $result): InitiatedPayment
    {
        return new InitiatedPayment(
            paymentPublicId: (string) $payment->public_id,
            status: $payment->status,
            attemptNumber: (int) $payment->attempt_number,
            amountMinor: (int) $payment->amount_minor,
            currency: (string) $payment->currency,
            providerPaymentReference: $payment->provider_payment_reference,
            clientInstructions: $result?->clientInstructions,
        );
    }
}
