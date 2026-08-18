<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\ProcessAffiliateAttribution;
use App\Support\AffiliateConfig;

/**
 * Queues affiliate attribution when an order is paid (P6-D2).
 *
 * Mirrors `QueueSecureDelivery` exactly: no business logic here, no database read, no
 * authority call — it checks the flag and dispatches an order-id-only job. The work happens
 * in the worker, never in the payment-confirmation request.
 *
 * It replaces what was first written as an `AFTER INSERT` trigger on `orders`, a form that
 * was wrong twice over: it attributed a `pending` order before any payment existed, and it
 * hung affiliate logic on EVERY insert into a table shared by P1/P3/P4, so any fault inside
 * attribution would have broken order creation for code with nothing to do with affiliation.
 * Attribution now happens at the paid transition, aligned with P6-A1.0 CRM attribution, and
 * `orders` carries no affiliate trigger at all.
 */
final class ResolveAffiliateAttribution
{
    public function handle(OrderPaid $event): void
    {
        if (! AffiliateConfig::governanceEnabled()) {
            return;
        }

        ProcessAffiliateAttribution::dispatch($event->orderId);
    }
}
