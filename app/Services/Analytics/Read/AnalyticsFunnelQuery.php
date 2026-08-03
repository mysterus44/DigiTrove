<?php

namespace App\Services\Analytics\Read;

use App\Services\Analytics\Read\Data\AnalyticsDateRange;
use App\Services\Analytics\Read\Data\AnalyticsFunnel;
use App\Services\Analytics\Read\Data\AnalyticsFunnelDay;
use App\Services\Analytics\Read\Data\AnalyticsFunnelRatios;
use App\Support\AnalyticsDashboardConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;

final class AnalyticsFunnelQuery
{
    public function __construct(private readonly AnalyticsReader $reader) {}

    public function get(?string $from = null, ?string $to = null): AnalyticsFunnel
    {
        $range = AnalyticsDateRange::make($from, $to);
        $key = implode('|', [
            'analytics:v1',
            'role=admin',
            'scope=global',
            'timezone=UTC',
            'query=funnel',
            'from='.$range->fromString(),
            'to='.$range->toString(),
            'currency=none',
            'page=none',
            'per_page=none',
        ]);
        $load = fn (): AnalyticsFunnel => $this->reader->run(
            fn (Connection $connection): AnalyticsFunnel => $this->load($connection, $range),
        );
        $ttl = AnalyticsDashboardConfig::cacheSeconds();

        return $ttl === 0 ? $load() : Cache::remember($key, $ttl, $load);
    }

    private function load(Connection $connection, AnalyticsDateRange $range): AnalyticsFunnel
    {
        $rowsByDay = [];

        foreach ($connection->select(<<<'SQL'
            SELECT day::text AS day, visitors, sessions, product_views,
                   checkouts, purchases, new_customers
            FROM public.daily_funnel_stats
            WHERE day BETWEEN ?::date AND ?::date
            ORDER BY day
            SQL, [$range->fromString(), $range->toString()]) as $row) {
            $rowsByDay[(string) $row->day] = new AnalyticsFunnelDay(
                (string) $row->day,
                (int) $row->visitors,
                (int) $row->sessions,
                (int) $row->product_views,
                (int) $row->checkouts,
                (int) $row->purchases,
                (int) $row->new_customers,
            );
        }

        $dailySeries = [];
        foreach ($range->days() as $day) {
            $dailySeries[] = $rowsByDay[$day] ?? new AnalyticsFunnelDay($day, null, null, null, null, null, null);
        }

        $totals = $connection->selectOne(<<<'SQL'
            SELECT
                coalesce(sum(visitors), 0)::bigint AS visitors,
                coalesce(sum(sessions), 0)::bigint AS sessions,
                coalesce(sum(product_views), 0)::bigint AS product_views,
                coalesce(sum(checkouts), 0)::bigint AS checkouts,
                coalesce(sum(purchases), 0)::bigint AS purchases,
                coalesce(sum(new_customers), 0)::bigint AS new_customers,
                CASE WHEN coalesce(sum(sessions), 0) = 0 THEN NULL
                     ELSE round(sum(product_views)::numeric / sum(sessions)::numeric, 6)::text END AS views_per_session,
                CASE WHEN coalesce(sum(sessions), 0) = 0 THEN NULL
                     ELSE round(sum(checkouts)::numeric / sum(sessions)::numeric, 6)::text END AS checkout_rate_per_session,
                CASE WHEN coalesce(sum(sessions), 0) = 0 THEN NULL
                     ELSE round(sum(purchases)::numeric / sum(sessions)::numeric, 6)::text END AS purchase_rate_per_session,
                CASE WHEN coalesce(sum(checkouts), 0) = 0 THEN NULL
                     ELSE round(sum(purchases)::numeric / sum(checkouts)::numeric, 6)::text END AS purchase_per_checkout,
                CASE WHEN coalesce(sum(purchases), 0) = 0 THEN NULL
                     ELSE round(sum(new_customers)::numeric / sum(purchases)::numeric, 6)::text END AS new_customer_share
            FROM public.daily_funnel_stats
            WHERE day BETWEEN ?::date AND ?::date
            SQL, [$range->fromString(), $range->toString()]);
        $calculatedDays = array_keys($rowsByDay);
        $today = CarbonImmutable::now('UTC')->toDateString();

        return new AnalyticsFunnel(
            $range->fromString(),
            $range->toString(),
            $calculatedDays[0] ?? null,
            $calculatedDays === [] ? null : $calculatedDays[array_key_last($calculatedDays)],
            array_values(array_diff($range->days(), $calculatedDays)),
            in_array($today, $calculatedDays, true),
            (int) $totals->visitors,
            (int) $totals->sessions,
            (int) $totals->product_views,
            (int) $totals->checkouts,
            (int) $totals->purchases,
            (int) $totals->new_customers,
            false,
            $dailySeries,
            new AnalyticsFunnelRatios(
                $totals->views_per_session,
                $totals->checkout_rate_per_session,
                $totals->purchase_rate_per_session,
                $totals->purchase_per_checkout,
                $totals->new_customer_share,
            ),
        );
    }
}
