<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\SecureDeliveryJob;
use App\Support\DeliveryConfig;

/**
 * Queues secure delivery when an order is paid (P4-C0, D-035).
 *
 * Minimal by design: it never loads the order, never generates a token and
 * never sends mail. It only dispatches a unique, order-id-only job — and only
 * when the delivery pipeline is explicitly enabled.
 */
final class QueueSecureDelivery
{
    public function handle(OrderPaid $event): void
    {
        if (! DeliveryConfig::enabled()) {
            return;
        }

        DeliveryConfig::assertPipelineReady();

        SecureDeliveryJob::dispatch($event->orderId);
    }
}
