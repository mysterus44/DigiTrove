<?php

namespace App\Services\Analytics\Read\Data;

final readonly class AnalyticsSalesCurrencySummary
{
    public function __construct(
        public string $currency,
        public int $ordersCount,
        public int $grossRevenueMinor,
        public int $discountMinor,
        public int $taxMinor,
        public int $refundsMinor,
        public int $netRevenueMinor,
        public int $averageOrderMinor,
    ) {}
}
