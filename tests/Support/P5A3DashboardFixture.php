<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

final class P5A3DashboardFixture
{
    public static function clear(): void
    {
        $owner = DB::connection('pgsql_migration');

        foreach (['daily_sales_stats', 'daily_product_stats', 'daily_product_engagement_stats', 'daily_funnel_stats'] as $table) {
            $owner->table($table)->delete();
        }
    }

    public static function funnel(
        string $day,
        int $visitors,
        int $sessions,
        int $views,
        int $carts,
        int $checkouts,
        int $purchases,
        int $customers,
    ): void {
        DB::connection('pgsql_migration')->table('daily_funnel_stats')->insert([
            'day' => $day,
            'visitors' => $visitors,
            'sessions' => $sessions,
            'product_views' => $views,
            'add_to_carts' => $carts,
            'checkouts' => $checkouts,
            'purchases' => $purchases,
            'new_customers' => $customers,
            'updated_at' => now('UTC'),
        ]);
    }

    public static function sales(
        string $day,
        string $currency,
        int $orders,
        int $gross,
        int $discount,
        int $tax,
        int $refunds,
        int $net,
        int $average,
    ): void {
        DB::connection('pgsql_migration')->table('daily_sales_stats')->insert([
            'day' => $day,
            'currency' => $currency,
            'orders_count' => $orders,
            'gross_revenue_minor' => $gross,
            'discount_minor' => $discount,
            'tax_minor' => $tax,
            'refunds_minor' => $refunds,
            'net_revenue_minor' => $net,
            'average_order_minor' => $average,
            'updated_at' => now('UTC'),
        ]);
    }
}
