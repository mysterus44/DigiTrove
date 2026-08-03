<?php

namespace App\Services\Analytics\Read\Data;

final readonly class AnalyticsProductRow
{
    public function __construct(
        public int $productId,
        public string $label,
        public int $views,
        public int $purchases,
        public int $revenueMinor,
        public ?int $averageRevenuePerPurchaseMinor,
    ) {}
}
