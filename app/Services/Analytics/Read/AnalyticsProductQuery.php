<?php

namespace App\Services\Analytics\Read;

use App\Services\Analytics\Read\Data\AnalyticsDateRange;
use App\Services\Analytics\Read\Data\AnalyticsProductResult;
use App\Services\Analytics\Read\Data\AnalyticsProductRow;
use App\Services\Analytics\Read\Data\AnalyticsProductSort;
use App\Support\AnalyticsDashboardConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class AnalyticsProductQuery
{
    public function __construct(private readonly AnalyticsReader $reader) {}

    public function get(
        string $currency,
        ?string $from = null,
        ?string $to = null,
        string $sort = 'revenue_desc',
        int $page = 1,
        int $perPage = 30,
    ): AnalyticsProductResult {
        $productSort = AnalyticsProductSort::tryFrom($sort);

        if (! preg_match('/^[A-Z]{3}$/', $currency)
            || $productSort === null
            || $page < 1
            || $perPage < 1
            || $perPage > 100) {
            throw new InvalidArgumentException('Invalid analytics product filters.');
        }

        $range = AnalyticsDateRange::make($from, $to);
        $key = implode('|', [
            'analytics:v1',
            'role=admin',
            'scope=global',
            'timezone=UTC',
            'query=products',
            'from='.$range->fromString(),
            'to='.$range->toString(),
            'currency='.$currency,
            'sort='.$productSort->value,
            'page='.$page,
            'per_page='.$perPage,
        ]);
        $load = fn (): AnalyticsProductResult => $this->reader->run(
            fn (Connection $connection): AnalyticsProductResult => $this->load(
                $connection,
                $range,
                $currency,
                $productSort,
                $page,
                $perPage,
            ),
        );
        $ttl = AnalyticsDashboardConfig::cacheSeconds();

        if ($ttl === 0) {
            return $load();
        }

        $cached = Cache::remember($key, $ttl, fn (): array => $this->toCache($load()));

        return $this->fromCache($cached);
    }

    private function load(
        Connection $connection,
        AnalyticsDateRange $range,
        string $currency,
        AnalyticsProductSort $sort,
        int $page,
        int $perPage,
    ): AnalyticsProductResult {
        $calculatedDays = array_map(
            static fn (object $row): string => (string) $row->day,
            $connection->select(<<<'SQL'
                SELECT day::text AS day
                FROM public.daily_funnel_stats
                WHERE day BETWEEN ?::date AND ?::date
                ORDER BY day
                SQL, [$range->fromString(), $range->toString()]),
        );
        $baseSql = <<<'SQL'
            WITH engagement AS (
                SELECT product_id, sum(views)::bigint AS views
                FROM public.daily_product_engagement_stats
                WHERE day BETWEEN ?::date AND ?::date
                GROUP BY product_id
            ),
            commerce AS (
                SELECT product_id,
                       sum(purchases)::bigint AS purchases,
                       sum(revenue_minor)::bigint AS revenue_minor
                FROM public.daily_product_stats
                WHERE currency = ? AND day BETWEEN ?::date AND ?::date
                GROUP BY product_id
            )
            SELECT coalesce(engagement.product_id, commerce.product_id)::bigint AS product_id,
                   coalesce(engagement.views, 0)::bigint AS views,
                   coalesce(commerce.purchases, 0)::bigint AS purchases,
                   coalesce(commerce.revenue_minor, 0)::bigint AS revenue_minor
            FROM engagement
            FULL OUTER JOIN commerce USING (product_id)
            WHERE coalesce(engagement.views, 0) > 0
               OR coalesce(commerce.purchases, 0) > 0
               OR coalesce(commerce.revenue_minor, 0) > 0
            SQL;
        $bindings = [
            $range->fromString(),
            $range->toString(),
            $currency,
            $range->fromString(),
            $range->toString(),
        ];
        $total = (int) $connection->scalar("SELECT count(*) FROM ({$baseSql}) AS product_metrics", $bindings);
        $offset = ($page - 1) * $perPage;
        $rows = [];

        foreach ($connection->select(
            "SELECT * FROM ({$baseSql}) AS product_metrics ORDER BY {$sort->orderBy()} LIMIT ? OFFSET ?",
            [...$bindings, $perPage, $offset],
        ) as $row) {
            $purchases = (int) $row->purchases;
            $revenue = (int) $row->revenue_minor;
            $productId = (int) $row->product_id;
            $rows[] = new AnalyticsProductRow(
                $productId,
                'Produit #'.$productId,
                (int) $row->views,
                $purchases,
                $revenue,
                $purchases === 0 ? null : intdiv($revenue, $purchases),
            );
        }

        $today = CarbonImmutable::now('UTC')->toDateString();

        return new AnalyticsProductResult(
            $currency,
            $sort->value,
            $range->fromString(),
            $range->toString(),
            $calculatedDays[0] ?? null,
            $calculatedDays === [] ? null : $calculatedDays[array_key_last($calculatedDays)],
            array_values(array_diff($range->days(), $calculatedDays)),
            in_array($today, $calculatedDays, true),
            $rows,
            $total,
            $page,
            $perPage,
            max(1, (int) ceil($total / $perPage)),
        );
    }

    /** @return array<string, mixed> */
    private function toCache(AnalyticsProductResult $result): array
    {
        return [
            'currency' => $result->currency,
            'sort' => $result->sort,
            'from' => $result->from,
            'to' => $result->to,
            'coverage_start' => $result->coverageStart,
            'coverage_end' => $result->coverageEnd,
            'missing_days' => $result->missingDays,
            'current_day_provisional' => $result->currentDayProvisional,
            'rows' => array_map(static fn (AnalyticsProductRow $row): array => [
                'product_id' => $row->productId,
                'label' => $row->label,
                'views' => $row->views,
                'purchases' => $row->purchases,
                'revenue_minor' => $row->revenueMinor,
                'average_revenue_per_purchase_minor' => $row->averageRevenuePerPurchaseMinor,
            ], $result->rows),
            'total' => $result->total,
            'page' => $result->page,
            'per_page' => $result->perPage,
            'last_page' => $result->lastPage,
        ];
    }

    /** @param array<string, mixed> $cached */
    private function fromCache(array $cached): AnalyticsProductResult
    {
        $rows = array_map(static fn (array $row): AnalyticsProductRow => new AnalyticsProductRow(
            (int) $row['product_id'],
            (string) $row['label'],
            (int) $row['views'],
            (int) $row['purchases'],
            (int) $row['revenue_minor'],
            $row['average_revenue_per_purchase_minor'] === null
                ? null
                : (int) $row['average_revenue_per_purchase_minor'],
        ), $cached['rows']);

        return new AnalyticsProductResult(
            (string) $cached['currency'],
            (string) $cached['sort'],
            (string) $cached['from'],
            (string) $cached['to'],
            is_string($cached['coverage_start']) ? $cached['coverage_start'] : null,
            is_string($cached['coverage_end']) ? $cached['coverage_end'] : null,
            $cached['missing_days'],
            (bool) $cached['current_day_provisional'],
            $rows,
            (int) $cached['total'],
            (int) $cached['page'],
            (int) $cached['per_page'],
            (int) $cached['last_page'],
        );
    }
}
