<?php

namespace App\Services\Analytics\Read\Data;

final readonly class AnalyticsProductResult
{
    /**
     * @param  list<string>  $missingDays
     * @param  list<AnalyticsProductRow>  $rows
     */
    public function __construct(
        public string $currency,
        public string $sort,
        public string $from,
        public string $to,
        public ?string $coverageStart,
        public ?string $coverageEnd,
        public array $missingDays,
        public bool $currentDayProvisional,
        public array $rows,
        public int $total,
        public int $page,
        public int $perPage,
        public int $lastPage,
    ) {}
}
