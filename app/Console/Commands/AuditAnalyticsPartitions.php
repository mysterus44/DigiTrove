<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\EventPartitionService;
use Illuminate\Console\Command;
use Throwable;

final class AuditAnalyticsPartitions extends Command
{
    protected $signature = 'analytics:partitions:audit';

    protected $description = 'Audit analytics event partitions without reading event payloads';

    public function handle(EventPartitionService $service): int
    {
        try {
            $this->line(json_encode($service->audit(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
