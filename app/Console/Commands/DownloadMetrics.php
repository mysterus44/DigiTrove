<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Delivery\DownloadOperationsService;
use Illuminate\Console\Command;

final class DownloadMetrics extends Command
{
    protected $signature = 'downloads:metrics';

    protected $description = 'Print aggregate secure delivery metrics';

    public function handle(DownloadOperationsService $operations): int
    {
        foreach ($operations->metrics() as $name => $value) {
            $this->line("{$name}={$value}");
        }

        return self::SUCCESS;
    }
}
