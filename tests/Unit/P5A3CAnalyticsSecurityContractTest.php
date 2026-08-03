<?php

$root = dirname(__DIR__, 2);

it('keeps product and funnel reads projection-only and identity-free', function () use ($root) {
    $product = file_get_contents($root.'/app/Services/Analytics/Read/AnalyticsProductQuery.php');
    $funnel = file_get_contents($root.'/app/Services/Analytics/Read/AnalyticsFunnelQuery.php');
    $queries = $product.$funnel;

    expect($product)->toContain('public.daily_product_stats')
        ->toContain('public.daily_product_engagement_stats')
        ->toContain('public.daily_funnel_stats')
        ->toContain("'query=products'")
        ->toContain("'role=admin'")
        ->toContain("'scope=global'")
        ->and($funnel)->toContain('public.daily_funnel_stats')
        ->toContain("'query=funnel'")
        ->not->toContain('public.daily_product_stats')
        ->not->toContain('public.daily_product_engagement_stats')
        ->and($queries)->not->toContain(' public.products')
        ->not->toContain(' public.orders')
        ->not->toContain(' public.order_items')
        ->not->toContain(' public.payments')
        ->not->toContain(' public.refunds')
        ->not->toContain(' public.events')
        ->not->toContain(' public.analytics_sessions')
        ->not->toContain('customer_email')
        ->not->toContain('user_id=')
        ->not->toContain('visitor_id=')
        ->not->toContain('purchases / views');
});

it('keeps the sort allowlist closed and user input out of SQL fragments', function () use ($root) {
    $sort = file_get_contents($root.'/app/Services/Analytics/Read/Data/AnalyticsProductSort.php');
    $product = file_get_contents($root.'/app/Services/Analytics/Read/AnalyticsProductQuery.php');

    expect($sort)->toContain("case RevenueDesc = 'revenue_desc'")
        ->toContain("case PurchasesDesc = 'purchases_desc'")
        ->toContain("case ViewsDesc = 'views_desc'")
        ->toContain("case ProductIdAsc = 'product_id_asc'")
        ->and($product)->toContain('$sort->orderBy()')
        ->not->toContain('$sortValue');
});

it('adds no migration operation API export or worker execution surface', function () use ($root) {
    $migrations = glob($root.'/database/migrations/*.php') ?: [];
    $surfaceFiles = array_merge(
        glob($root.'/app/Filament/Pages/*.php') ?: [],
        glob($root.'/app/Filament/Widgets/*.php') ?: [],
        glob($root.'/resources/views/filament/widgets/*.blade.php') ?: [],
    );
    $surface = implode("\n", array_map('file_get_contents', $surfaceFiles));

    expect($migrations)->toHaveCount(35)
        ->and(implode("\n", $migrations))->not->toContain('000020')
        ->and($surface)->not->toContain('Artisan::call')
        ->not->toContain('Process::run')
        ->not->toContain('analytics:rollup')
        ->not->toContain('partitions:ensure')
        ->not->toContain('digitrove_analytics_worker')
        ->not->toContain('SQLSTATE')
        ->not->toContain('csv')
        ->not->toContain('export');
});
