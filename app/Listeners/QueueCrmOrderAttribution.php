<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Jobs\ProcessCrmOrderAttribution;
use App\Support\CrmConfig;
use RuntimeException;

final class QueueCrmOrderAttribution
{
    public function handle(OrderPaid $event): void
    {
        try {
            if (! CrmConfig::enabled() || ! CrmConfig::orderAttributionProcessingEnabled()) {
                return;
            }
        } catch (RuntimeException) {
            return;
        }

        ProcessCrmOrderAttribution::dispatch($event->orderId);
    }
}
