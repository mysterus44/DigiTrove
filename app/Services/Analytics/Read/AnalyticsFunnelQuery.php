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

        if ($ttl === 0) {
            return $load();
        }

        $cached = Cache::remember($key, $ttl, fn (): array => $this->toCache($load()));

        return $this->fromCache($cached);
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

    /** @return array<string, mixed> */
    private function toCache(AnalyticsFunnel $result): array
    {
        return [
            'from' => $result->from,
            'to' => $result->to,
            'coverage_start' => $result->coverageStart,
            'coverage_end' => $result->coverageEnd,
            'missing_days' => $result->missingDays,
            'current_day_provisional' => $result->currentDayProvisional,
            'visitors' => $result->visitors,
            'sessions' => $result->sessions,
            'product_views' => $result->productViews,
            'checkouts' => $result->checkouts,
            'purchases' => $result->purchases,
            'new_customers' => $result->newCustomers,
            'add_to_carts_tracked' => $result->addToCartsTracked,
            'daily_series' => array_map(static fn (AnalyticsFunnelDay $day): array => [
                'day' => $day->day,
                'visitors' => $day->visitors,
                'sessions' => $day->sessions,
                'product_views' => $day->productViews,
                'checkouts' => $day->checkouts,
                'purchases' => $day->purchases,
                'new_customers' => $day->newCustomers,
            ], $result->dailySeries),
            'ratios' => [
                'views_per_session' => $result->ratios->viewsPerSession,
                'checkout_rate_per_session' => $result->ratios->checkoutRatePerSession,
                'purchase_rate_per_session' => $result->ratios->purchaseRatePerSession,
                'purchase_per_checkout' => $result->ratios->purchasePerCheckout,
                'new_customer_share' => $result->ratios->newCustomerShare,
            ],
        ];
    }

    /** @param array<string, mixed> $cached */
    private function fromCache(array $cached): AnalyticsFunnel
    {
        $series = array_map(static fn (array $day): AnalyticsFunnelDay => new AnalyticsFunnelDay(
            (string) $day['day'],
            is_int($day['visitors']) ? $day['visitors'] : null,
            is_int($day['sessions']) ? $day['sessions'] : null,
            is_int($day['product_views']) ? $day['product_views'] : null,
            is_int($day['checkouts']) ? $day['checkouts'] : null,
            is_int($day['purchases']) ? $day['purchases'] : null,
            is_int($day['new_customers']) ? $day['new_customers'] : null,
        ), $cached['daily_series']);
        $ratios = $cached['ratios'];

        return new AnalyticsFunnel(
            (string) $cached['from'],
            (string) $cached['to'],
            is_string($cached['coverage_start']) ? $cached['coverage_start'] : null,
            is_string($cached['coverage_end']) ? $cached['coverage_end'] : null,
            $cached['missing_days'],
            (bool) $cached['current_day_provisional'],
            (int) $cached['visitors'],
            (int) $cached['sessions'],
            (int) $cached['product_views'],
            (int) $cached['checkouts'],
            (int) $cached['purchases'],
            (int) $cached['new_customers'],
            (bool) $cached['add_to_carts_tracked'],
            $series,
            new AnalyticsFunnelRatios(
                is_string($ratios['views_per_session']) ? $ratios['views_per_session'] : null,
                is_string($ratios['checkout_rate_per_session']) ? $ratios['checkout_rate_per_session'] : null,
                is_string($ratios['purchase_rate_per_session']) ? $ratios['purchase_rate_per_session'] : null,
                is_string($ratios['purchase_per_checkout']) ? $ratios['purchase_per_checkout'] : null,
                is_string($ratios['new_customer_share']) ? $ratios['new_customer_share'] : null,
            ),
        );
    }
}
