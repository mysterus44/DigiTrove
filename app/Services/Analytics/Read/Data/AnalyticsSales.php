<?php

namespace App\Services\Analytics\Read\Data;

final readonly class AnalyticsSales
{
    /**
     * @param  list<AnalyticsSalesDay>  $items
     * @param  list<AnalyticsSalesDay>  $series
     * @param  list<string>  $missingDays
     */
    public function __construct(
        public string $currency,
        public string $from,
        public string $to,
        public int $page,
        public int $perPage,
        public int $totalRows,
        public int $lastPage,
        public array $items,
        public array $series,
        public AnalyticsSalesCurrencySummary $totals,
        public array $missingDays,
        public bool $currentDayProvisional,
    ) {}
}
