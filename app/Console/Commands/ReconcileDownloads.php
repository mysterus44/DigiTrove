<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Delivery\DownloadOperationsService;
use Illuminate\Console\Command;

final class ReconcileDownloads extends Command
{
    protected $signature = 'downloads:reconcile';

    protected $description = 'Reconcile stale secure download attempts';

    public function handle(DownloadOperationsService $operations): int
    {
        $this->line('reconciled='.$operations->reconcileStarted());

        return self::SUCCESS;
    }
}
