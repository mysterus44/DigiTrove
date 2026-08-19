<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\RefundSucceeded;
use App\Jobs\ProcessAffiliateRefundReversal;
use App\Support\AffiliateConfig;

/**
 * Queues the affiliate commission reversal when a refund succeeds (P6-D3).
 *
 * Mirrors `ResolveAffiliateAttribution` exactly: no business logic, no database read, no
 * authority call — it checks the flag and dispatches a refund-id-only job.
 */
final class QueueAffiliateRefundReversal
{
    public function handle(RefundSucceeded $event): void
    {
        if (! AffiliateConfig::governanceEnabled()) {
            return;
        }

        ProcessAffiliateRefundReversal::dispatch($event->refundId);
    }
}
