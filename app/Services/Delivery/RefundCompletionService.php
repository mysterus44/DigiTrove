<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\GrantRevocationReason;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Events\RefundSucceeded;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\RefundCompletionResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * Finalises an already provider-confirmed refund locally (P4-C2, D-035).
 *
 * A pure local primitive: NO network call. It climbs the refund ladder
 * (pending → processing → succeeded), then moves the order to
 * `partially_refunded` (grants kept) or `refunded` (all active grants revoked in
 * the SAME transaction, satisfying the deferred G4). It never exceeds the
 * captured payment amount (a DB cap trigger is the last line of defence).
 */
final class RefundCompletionService
{
    public function completeSucceededRefund(
        int $refundId,
        string $providerRefundReference,
        ?string $providerStatus = null,
        ?CarbonImmutable $at = null,
    ): RefundCompletionResult {
        $now = $at ?? CarbonImmutable::now();
        $reference = trim($providerRefundReference);

        if ($reference === '') {
            throw new RuntimeException('A provider refund reference is required.');
        }

        $result = DB::transaction(function () use ($refundId, $reference, $providerStatus, $now): RefundCompletionResult {
            // Resolve the immutable payment pointer without a lock, then acquire
            // the gate's stable lock order: Payment -> Order -> Refund.
            $pointer = Refund::query()->select(['id', 'payment_id'])->whereKey($refundId)->first();

            if ($pointer === null) {
                throw new RuntimeException('The refund could not be resolved.');
            }

            $payment = Payment::query()->whereKey($pointer->payment_id)->lockForUpdate()->first();
            if ($payment === null) {
                throw new RuntimeException('The refund payment could not be resolved.');
            }

            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();
            if ($order === null) {
                throw new RuntimeException('The refund order could not be resolved.');
            }

            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->first();
            if ($refund === null || (int) $refund->payment_id !== (int) $payment->id) {
                throw new RuntimeException('The refund could not be resolved.');
            }

            // Idempotent replay: an already-succeeded refund with the same
            // reference is a no-op; a different reference is a conflict.
            if ($refund->status === RefundStatus::Succeeded) {
                if ((string) $refund->provider_refund_reference !== $reference) {
                    throw new RuntimeException('The refund already has a different provider reference.');
                }

                return new RefundCompletionResult($refund->id, $order->status->value, true, 0);
            }

            if (! in_array($refund->status, [RefundStatus::Pending, RefundStatus::Processing], true)) {
                throw new RuntimeException('The refund can no longer be completed.');
            }

            if ($refund->provider_refund_reference !== null && (string) $refund->provider_refund_reference !== $reference) {
                throw new RuntimeException('The refund already has a different provider reference.');
            }

            $this->climbToSucceeded($refund, $reference, $providerStatus, $now);

            $succeededSum = (int) Refund::query()
                ->where('payment_id', $payment->id)
                ->where('status', RefundStatus::Succeeded->value)
                ->sum('amount_minor');

            if ($succeededSum > (int) $payment->amount_minor) {
                throw new RuntimeException('The refund total exceeds the captured amount.');
            }

            $revoked = 0;
            if ($succeededSum === (int) $payment->amount_minor) {
                $order->forceFill(['status' => OrderStatus::Refunded->value])->save();
                $revoked = $this->revokeActiveGrants($order, $now);
            } else {
                $order->forceFill(['status' => OrderStatus::PartiallyRefunded->value])->save();
            }

            return new RefundCompletionResult($refund->id, $order->status->value, false, $revoked);
        });

        // AFTER COMMIT, never inside — same rule as `OrderPaid` (D-034). A consumer fault
        // must never be able to roll a refund back. Fired on a replay too: the downstream
        // reversal authority is idempotent by construction, and re-firing is the only way a
        // reversal lost to a crashed worker gets a second chance (P6-D3).
        Event::dispatch(new RefundSucceeded($result->refundId));

        return $result;
    }

    private function climbToSucceeded(Refund $refund, string $reference, ?string $providerStatus, CarbonImmutable $now): void
    {
        // The trigger forbids pending -> succeeded directly.
        if ($refund->status === RefundStatus::Pending) {
            $refund->forceFill([
                'status' => RefundStatus::Processing->value,
                'processing_at' => $refund->processing_at ?? $now,
            ])->save();
        }

        $fill = [
            'status' => RefundStatus::Succeeded->value,
            'succeeded_at' => $now,
            'last_verified_at' => $now,
        ];
        if ($refund->provider_refund_reference === null) {
            $fill['provider_refund_reference'] = $reference;
        }
        if ($providerStatus !== null) {
            $fill['provider_status'] = $providerStatus;
        }

        $refund->forceFill($fill)->save();
    }

    private function revokeActiveGrants(Order $order, CarbonImmutable $now): int
    {
        $grants = DownloadGrant::query()
            ->whereIn('order_item_id', OrderItem::query()->where('order_id', $order->id)->select('id'))
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->get();

        foreach ($grants as $grant) {
            $grant->forceFill([
                'revoked_at' => $now,
                'revoked_reason_code' => GrantRevocationReason::FullRefund->value,
            ])->save();
        }

        return $grants->count();
    }
}
