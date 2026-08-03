<?php

namespace App\Services\Analytics\Read\Data;

final readonly class AnalyticsFunnel
{
    /**
     * @param  list<string>  $missingDays
     * @param  list<AnalyticsFunnelDay>  $dailySeries
     */
    public function __construct(
        public string $from,
        public string $to,
        public ?string $coverageStart,
        public ?string $coverageEnd,
        public array $missingDays,
        public bool $currentDayProvisional,
        public int $visitors,
        public int $sessions,
        public int $productViews,
        public int $checkouts,
        public int $purchases,
        public int $newCustomers,
        public bool $addToCartsTracked,
        public array $dailySeries,
        public AnalyticsFunnelRatios $ratios,
    ) {}
}
