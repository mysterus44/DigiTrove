<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Affiliate\AffiliateCommissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Accrues an order's affiliate commissions, in the worker (P6-D3).
 *
 * The serialised payload is EXACTLY `orderId` — no affiliate, no rate, no amount, no model.
 *
 * It is dispatched by `ProcessAffiliateAttribution` once the attribution is RESOLVED, not by
 * a second listener on `OrderPaid`. Two independent listeners would race: an accrual needs
 * the attribution row to exist, and nothing orders two listeners of the same event.
 *
 * `accrue_affiliate_commissions` is idempotent (`already_accrued`), and the partial unique
 * index `affiliate_commission_entries_single_accrual` refuses a second accrual even under a
 * race, so the queue's at-least-once semantics cannot double-pay anyone.
 */
final class ProcessAffiliateCommissionAccrual implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $orderId)
    {
        $this->afterCommit();
    }

    /** One live accrual attempt per order. */
    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    /** @return list<int> bounded, growing backoff between retries (seconds). */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(AffiliateCommissionService $commissions): void
    {
        $commissions->accrueForOrder($this->orderId);
    }
}
