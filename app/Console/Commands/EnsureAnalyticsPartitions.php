<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\EventPartitionService;
use App\Support\AnalyticsOperationsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class EnsureAnalyticsPartitions extends Command
{
    protected $signature = 'analytics:partitions:ensure
        {--month= : One month in YYYY-MM format; defaults to the configured window}';

    protected $description = 'Ensure bounded monthly analytics event partitions';

    public function handle(EventPartitionService $service): int
    {
        try {
            foreach ($this->months() as $month) {
                $result = $service->ensure($month);
                $this->line(json_encode([
                    'partition' => $result->partition,
                    'created' => $result->created,
                    'bound' => $result->bound,
                ], JSON_THROW_ON_ERROR));
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function months(): array
    {
        $requested = $this->option('month');

        if ($requested !== null) {
            $month = CarbonImmutable::createFromFormat('!Y-m', (string) $requested, 'UTC');

            if ($month === false || $month->format('Y-m') !== $requested) {
                throw new RuntimeException('The analytics partition month is invalid.');
            }

            return [$month];
        }

        $current = CarbonImmutable::now('UTC')->startOfMonth();
        $months = [];

        for ($offset = -AnalyticsOperationsConfig::partitionMonthsBehind();
            $offset <= AnalyticsOperationsConfig::partitionMonthsAhead();
            $offset++) {
            $months[] = $current->addMonths($offset);
        }

        return $months;
    }
}
