<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crm\CrmSegmentGenerationDispatcher;
use App\Support\CrmConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recovery sweep for durable segment generations. Fail-closed: when rebuild processing
 * is disabled it dispatches nothing. It never starts a generation — that stays an
 * explicit operator action.
 */
final class SweepCrmSegmentGenerations extends Command
{
    protected $signature = 'crm:sweep-segment-generations';

    protected $description = 'Dispatch due CRM segment generation rebuilds (recovery sweep)';

    public function handle(CrmSegmentGenerationDispatcher $dispatcher): int
    {
        try {
            if (! CrmConfig::segmentRebuildProcessingEnabled()) {
                $this->line('dispatched=0');

                return self::SUCCESS;
            }

            $this->line('dispatched='.$dispatcher->dispatchDue());

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('dispatched=0');

            return self::FAILURE;
        }
    }
}
