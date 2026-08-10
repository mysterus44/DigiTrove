<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cart\CartAbandonmentService;
use Illuminate\Console\Command;
use Throwable;

/**
 * P6-C abandonment detection. Bounded, idempotent, and output is AGGREGATE ONLY: never
 * a cart identifier, an address or anything that could identify a customer in a log.
 */
final class DetectAbandonedCarts extends Command
{
    protected $signature = 'crm:detect-abandoned-carts';

    protected $description = 'Mark inactive carts as abandoned (bounded, idempotent)';

    public function handle(CartAbandonmentService $abandonment): int
    {
        try {
            $detected = $abandonment->detect();
        } catch (Throwable) {
            // Disabled, unconfigured or unavailable: all report the same aggregate.
            $this->line('mode=disabled');
            $this->line('transitioned=0');

            return self::SUCCESS;
        }

        $this->line('mode=execute');
        $this->line('transitioned='.count($detected));

        return self::SUCCESS;
    }
}
