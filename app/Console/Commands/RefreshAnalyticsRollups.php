<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AuthoritativeRollupService;
use App\Support\AnalyticsOperationsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class RefreshAnalyticsRollups extends Command
{
    protected $signature = 'analytics:rollup
        {--date= : Final UTC day in YYYY-MM-DD format; defaults to yesterday}
        {--days=1 : Number of consecutive days ending on --date}';

    protected $description = 'Recalculate authoritative daily analytics projections';

    public function handle(AuthoritativeRollupService $service): int
    {
        try {
            $end = $this->option('date') === null
                ? CarbonImmutable::now('UTC')->subDay()->startOfDay()
                : CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('date'), 'UTC');
            $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);

            if ($end === false
                || $end->toDateString() !== (string) ($this->option('date') ?? $end->toDateString())
                || $days === false
                || $days < 1
                || $days > AnalyticsOperationsConfig::maxBackfillDays()) {
                throw new \RuntimeException('The analytics rollup date range is invalid.');
            }

            for ($offset = $days - 1; $offset >= 0; $offset--) {
                $result = $service->refresh($end->subDays($offset));
                $this->line(json_encode([
                    'day' => $result->day->toDateString(),
                    'sales_rows' => $result->salesRows,
                    'product_rows' => $result->productRows,
                    'engagement_rows' => $result->engagementRows,
                    'funnel_rows' => $result->funnelRows,
                ], JSON_THROW_ON_ERROR));
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
