<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Affiliate\AffiliateRefundReversalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reverses affiliate commissions after a succeeded refund, in the worker (P6-D3).
 *
 * The serialised payload is EXACTLY `refundId` — no order, no amount, no allocation, no
 * affiliate, no model. The split is recomputed in the worker from Commerce data.
 *
 * It exists so no affiliate logic runs inside the refund transaction. `RefundSucceeded` is
 * dispatched after COMMIT, so a fault here can never roll a refund back — and the reversal
 * is idempotent through the partial unique index on `(commission_id, refund_id)`.
 */
final class ProcessAffiliateRefundReversal implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $refundId)
    {
        $this->afterCommit();
    }

    /** One live reversal attempt per refund. */
    public function uniqueId(): string
    {
        return (string) $this->refundId;
    }

    /** @return list<int> bounded, growing backoff between retries (seconds). */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(AffiliateRefundReversalService $reversals): void
    {
        $reversals->reverseForRefund($this->refundId);
    }
}
