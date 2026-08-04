<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crm\CrmOrderAttributionDispatcher;
use App\Support\CrmConfig;
use Illuminate\Console\Command;
use Throwable;

final class DispatchCrmOrderAttributions extends Command
{
    protected $signature = 'crm:dispatch-order-attributions';

    protected $description = 'Dispatch due durable CRM order attributions';

    public function handle(CrmOrderAttributionDispatcher $dispatcher): int
    {
        try {
            if (! CrmConfig::orderAttributionProcessingEnabled()) {
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
