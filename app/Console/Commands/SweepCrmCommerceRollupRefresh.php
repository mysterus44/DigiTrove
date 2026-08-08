<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crm\CrmCommerceRollupRefreshDispatcher;
use App\Support\CrmConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recovery sweep for the durable rollup-refresh outbox. Fail-closed: when the feature
 * is disabled it dispatches nothing. It performs no historical backfill (P6-A1.3).
 */
final class SweepCrmCommerceRollupRefresh extends Command
{
    protected $signature = 'crm:sweep-commerce-rollup-refresh';

    protected $description = 'Dispatch due durable CRM commerce rollup refreshes (recovery sweep, no backfill)';

    public function handle(CrmCommerceRollupRefreshDispatcher $dispatcher): int
    {
        try {
            if (! CrmConfig::commerceRollupRefreshProcessingEnabled()) {
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
