<?php

namespace App\Services\Analytics\Read\Data;

final readonly class AnalyticsOverview
{
    /**
     * @param  list<string>  $missingDays
     * @param  array<string, AnalyticsSalesCurrencySummary>  $salesByCurrency
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
        public int $addToCarts,
        public int $checkouts,
        public int $purchases,
        public int $newCustomers,
        public array $salesByCurrency,
    ) {}
}
