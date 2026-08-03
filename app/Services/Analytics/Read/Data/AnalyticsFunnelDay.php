<?php

namespace App\Services\Analytics\Read\Data;

final readonly class AnalyticsFunnelDay
{
    public function __construct(
        public string $day,
        public ?int $visitors,
        public ?int $sessions,
        public ?int $productViews,
        public ?int $checkouts,
        public ?int $purchases,
        public ?int $newCustomers,
    ) {}
}
