<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Delivery\DownloadOperationsService;
use Illuminate\Console\Command;

final class DetectDownloadAbuse extends Command
{
    protected $signature = 'downloads:detect-abuse {--dry-run}';

    protected $description = 'Report aggregate secure download abuse signals';

    public function handle(DownloadOperationsService $operations): int
    {
        foreach ($operations->detectAbuse() as $alert) {
            $this->line("grant_id={$alert['grant_id']} distinct_ip_count={$alert['distinct_ip_count']}");
        }

        return self::SUCCESS;
    }
}
