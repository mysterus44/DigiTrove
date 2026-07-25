<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use JsonException;

final readonly class AnalyticsRollupResult
{
    public function __construct(
        public CarbonImmutable $day,
        public int $salesRows,
        public int $productRows,
        public int $engagementRows,
        public int $funnelRows,
    ) {}

    /**
     * @throws JsonException
     */
    public static function fromDatabaseJson(string $json): self
    {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self(
            CarbonImmutable::parse($value['day'], 'UTC')->startOfDay(),
            (int) $value['daily_sales_rows'],
            (int) $value['daily_product_rows'],
            (int) $value['daily_product_engagement_rows'],
            (int) $value['daily_funnel_rows'],
        );
    }
}
