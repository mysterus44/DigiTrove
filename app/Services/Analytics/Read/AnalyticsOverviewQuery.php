<?php

namespace App\Services\Analytics\Read;

use App\Services\Analytics\Read\Data\AnalyticsDateRange;
use App\Services\Analytics\Read\Data\AnalyticsOverview;
use App\Services\Analytics\Read\Data\AnalyticsSalesCurrencySummary;
use App\Support\AnalyticsDashboardConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;

final class AnalyticsOverviewQuery
{
    public function __construct(private readonly AnalyticsReader $reader) {}

    public function get(?string $from = null, ?string $to = null): AnalyticsOverview
    {
        $range = AnalyticsDateRange::make($from, $to);
        $key = implode('|', [
            'analytics:v1',
            'role=admin',
            'scope=global',
            'timezone=UTC',
            'query=overview',
            'from='.$range->fromString(),
            'to='.$range->toString(),
            'currency=none',
            'page=none',
            'per_page=none',
        ]);
        $load = fn (): AnalyticsOverview => $this->reader->run(
            fn (Connection $connection): AnalyticsOverview => $this->load($connection, $range),
        );
        $ttl = AnalyticsDashboardConfig::cacheSeconds();

        return $ttl === 0 ? $load() : Cache::remember($key, $ttl, $load);
    }

    private function load(Connection $connection, AnalyticsDateRange $range): AnalyticsOverview
    {
        $days = array_map(
            static fn (object $row): string => (string) $row->day,
            $connection->select(<<<'SQL'
                SELECT day::text AS day
                FROM public.daily_funnel_stats
                WHERE day BETWEEN ?::date AND ?::date
                ORDER BY day
                SQL, [$range->fromString(), $range->toString()]),
        );
        $funnel = $connection->selectOne(<<<'SQL'
            SELECT
                min(day)::text AS coverage_start,
                max(day)::text AS coverage_end,
                coalesce(sum(visitors), 0)::bigint AS visitors,
                coalesce(sum(sessions), 0)::bigint AS sessions,
                coalesce(sum(product_views), 0)::bigint AS product_views,
                coalesce(sum(checkouts), 0)::bigint AS checkouts,
                coalesce(sum(purchases), 0)::bigint AS purchases,
                coalesce(sum(new_customers), 0)::bigint AS new_customers
            FROM public.daily_funnel_stats
            WHERE day BETWEEN ?::date AND ?::date
            SQL, [$range->fromString(), $range->toString()]);

        $sales = [];
        foreach ($connection->select(<<<'SQL'
            SELECT currency,
                   sum(orders_count)::bigint AS orders_count,
                   sum(gross_revenue_minor)::bigint AS gross_revenue_minor,
                   sum(discount_minor)::bigint AS discount_minor,
                   sum(tax_minor)::bigint AS tax_minor,
                   sum(refunds_minor)::bigint AS refunds_minor,
                   sum(net_revenue_minor)::bigint AS net_revenue_minor
            FROM public.daily_sales_stats
            WHERE day BETWEEN ?::date AND ?::date
            GROUP BY currency
            ORDER BY currency
            SQL, [$range->fromString(), $range->toString()]) as $row) {
            $orders = (int) $row->orders_count;
            $captured = (int) $row->gross_revenue_minor - (int) $row->discount_minor + (int) $row->tax_minor;
            $sales[(string) $row->currency] = new AnalyticsSalesCurrencySummary(
                (string) $row->currency,
                $orders,
                (int) $row->gross_revenue_minor,
                (int) $row->discount_minor,
                (int) $row->tax_minor,
                (int) $row->refunds_minor,
                (int) $row->net_revenue_minor,
                $orders === 0 ? 0 : intdiv($captured, $orders),
            );
        }

        $today = CarbonImmutable::now('UTC')->toDateString();

        return new AnalyticsOverview(
            $range->fromString(),
            $range->toString(),
            $funnel->coverage_start,
            $funnel->coverage_end,
            array_values(array_diff($range->days(), $days)),
            in_array($today, $days, true),
            (int) $funnel->visitors,
            (int) $funnel->sessions,
            (int) $funnel->product_views,
            (int) $funnel->checkouts,
            (int) $funnel->purchases,
            (int) $funnel->new_customers,
            $sales,
        );
    }
}
