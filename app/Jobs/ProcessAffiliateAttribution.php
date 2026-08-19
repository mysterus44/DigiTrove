<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Affiliate\AffiliateAttributionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Attributes a paid order to an affiliate, in the worker (P6-D2).
 *
 * The serialised payload is EXACTLY `orderId` — no visitor id, no code, no affiliate, no
 * model. Everything else is read by the PostgreSQL authority the service calls.
 *
 * It exists so no affiliate logic runs inside the payment-confirmation request. `OrderPaid`
 * is dispatched after COMMIT, so a fault here could never roll a payment back — but a
 * synchronous listener would still let an exception in the attribution authority fail the
 * HTTP response to the provider webhook, AFTER the payment was correctly recorded. That is
 * the coupling `QueueSecureDelivery` already exists to avoid.
 *
 * `resolve_affiliate_attribution` is idempotent (`already_attributed`), so the queue's
 * at-least-once semantics cost nothing: a redelivery attributes no one twice.
 */
final class ProcessAffiliateAttribution implements ShouldBeUnique, ShouldQueue
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

    /** One live attribution attempt per order. */
    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    /** @return list<int> bounded, growing backoff between retries (seconds). */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(AffiliateAttributionService $attributions): void
    {
        $status = $attributions->resolveForOrder($this->orderId);

        // P6-D3 chains from HERE, not from a second listener on `OrderPaid`. An accrual needs
        // the attribution row to exist, and nothing orders two listeners of the same event —
        // they would race, and the loser would find no attribution to commission.
        //
        // Only these two statuses mean "an attribution exists for this order". The others —
        // `no_match`, `no_active_policy`, `no_such_order`, `disabled` — mean there is nothing
        // to commission, and chaining on them would queue work that can only fail.
        //
        // A direct dispatch, not `Bus::chain`: this repository uses `Bus::chain` NOWHERE, and
        // a mechanism the codebase does not already use is not introduced for one call site.
        if (in_array($status, ['attributed', 'already_attributed'], true)) {
            ProcessAffiliateCommissionAccrual::dispatch($this->orderId);
        }
    }
}
