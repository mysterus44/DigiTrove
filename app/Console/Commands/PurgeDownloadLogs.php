<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Delivery\DownloadOperationsService;
use Illuminate\Console\Command;

final class PurgeDownloadLogs extends Command
{
    protected $signature = 'downloads:purge {--dry-run}';

    protected $description = 'Purge terminal download logs after retention';

    public function handle(DownloadOperationsService $operations): int
    {
        $this->line('purgeable='.$operations->purgeLogs((bool) $this->option('dry-run')));

        return self::SUCCESS;
    }
}
