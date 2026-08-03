<?php

namespace App\Services\Analytics\Read;

use App\Services\Analytics\Read\Data\AnalyticsDateRange;
use App\Services\Analytics\Read\Data\AnalyticsSales;
use App\Services\Analytics\Read\Data\AnalyticsSalesCurrencySummary;
use App\Services\Analytics\Read\Data\AnalyticsSalesDay;
use App\Support\AnalyticsDashboardConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class AnalyticsSalesQuery
{
    public function __construct(private readonly AnalyticsReader $reader) {}

    public function get(
        string $currency,
        ?string $from = null,
        ?string $to = null,
        int $page = 1,
        int $perPage = 30,
    ): AnalyticsSales {
        $currency = strtoupper($currency);

        if (! preg_match('/^[A-Z]{3}$/', $currency) || $page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Invalid analytics sales filters.');
        }

        $range = AnalyticsDateRange::make($from, $to);
        $key = implode('|', [
            'analytics-dashboard',
            'scope=global',
            'view=sales',
            'currency='.$currency,
            'from='.$range->fromString(),
            'to='.$range->toString(),
            'page='.$page,
            'perPage='.$perPage,
        ]);
        $load = fn (): AnalyticsSales => $this->reader->run(
            fn (Connection $connection): AnalyticsSales => $this->load($connection, $range, $currency, $page, $perPage),
        );
        $ttl = AnalyticsDashboardConfig::cacheSeconds();

        return $ttl === 0 ? $load() : Cache::remember($key, $ttl, $load);
    }

    private function load(
        Connection $connection,
        AnalyticsDateRange $range,
        string $currency,
        int $page,
        int $perPage,
    ): AnalyticsSales {
        $calculatedDays = array_map(
            static fn (object $row): string => (string) $row->day,
            $connection->select(<<<'SQL'
                SELECT day::text AS day
                FROM public.daily_funnel_stats
                WHERE day BETWEEN ?::date AND ?::date
                ORDER BY day
                SQL, [$range->fromString(), $range->toString()]),
        );
        $salesByDay = [];

        foreach ($connection->select(<<<'SQL'
            SELECT day::text AS day, orders_count, gross_revenue_minor,
                   discount_minor, tax_minor, refunds_minor, net_revenue_minor,
                   average_order_minor
            FROM public.daily_sales_stats
            WHERE currency = ? AND day BETWEEN ?::date AND ?::date
            ORDER BY day
            SQL, [$currency, $range->fromString(), $range->toString()]) as $row) {
            $salesByDay[(string) $row->day] = $this->dayFromRow($row, $currency);
        }

        $series = [];
        foreach ($calculatedDays as $day) {
            $series[] = $salesByDay[$day] ?? new AnalyticsSalesDay($day, $currency, 0, 0, 0, 0, 0, 0, 0);
        }

        $totals = $this->totals($series, $currency);
        $descending = array_reverse($series);
        $totalRows = count($descending);
        $lastPage = max(1, (int) ceil($totalRows / $perPage));
        $items = array_values(array_slice($descending, ($page - 1) * $perPage, $perPage));
        $today = CarbonImmutable::now('UTC')->toDateString();

        return new AnalyticsSales(
            $currency,
            $range->fromString(),
            $range->toString(),
            $page,
            $perPage,
            $totalRows,
            $lastPage,
            $items,
            $series,
            $totals,
            array_values(array_diff($range->days(), $calculatedDays)),
            in_array($today, $calculatedDays, true),
        );
    }

    private function dayFromRow(object $row, string $currency): AnalyticsSalesDay
    {
        return new AnalyticsSalesDay(
            (string) $row->day,
            $currency,
            (int) $row->orders_count,
            (int) $row->gross_revenue_minor,
            (int) $row->discount_minor,
            (int) $row->tax_minor,
            (int) $row->refunds_minor,
            (int) $row->net_revenue_minor,
            (int) $row->average_order_minor,
        );
    }

    /** @param list<AnalyticsSalesDay> $series */
    private function totals(array $series, string $currency): AnalyticsSalesCurrencySummary
    {
        $orders = array_sum(array_column($series, 'ordersCount'));
        $gross = array_sum(array_column($series, 'grossRevenueMinor'));
        $discount = array_sum(array_column($series, 'discountMinor'));
        $tax = array_sum(array_column($series, 'taxMinor'));
        $refunds = array_sum(array_column($series, 'refundsMinor'));
        $net = array_sum(array_column($series, 'netRevenueMinor'));

        return new AnalyticsSalesCurrencySummary(
            $currency,
            $orders,
            $gross,
            $discount,
            $tax,
            $refunds,
            $net,
            $orders === 0 ? 0 : intdiv($gross - $discount + $tax, $orders),
        );
    }
}
