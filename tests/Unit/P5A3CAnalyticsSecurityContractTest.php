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

it('keeps product identifiers non-metric and monetary and coverage semantics explicit', function () use ($root) {
    $chart = file_get_contents($root.'/app/Filament/Widgets/AnalyticsProductChart.php');
    $productTable = file_get_contents($root.'/resources/views/filament/widgets/analytics-product-table.blade.php');
    $funnelStats = file_get_contents($root.'/resources/views/filament/widgets/analytics-funnel-stats.blade.php');

    expect($chart)->toContain("AnalyticsProductSort::ProductIdAsc => ['Vues globales — tri par identifiant', 'views'")
        ->not->toContain("AnalyticsProductSort::ProductIdAsc => ['Identifiant produit', 'productId'")
        ->toContain('AnalyticsProductSort::RevenueDesc => [\'Revenu \'.$result->currency.\' — unités mineures\', \'revenueMinor\'')
        ->and($productTable)->toContain('Revenu {{ $result->currency }} — unités mineures')
        ->toContain('Moyenne par achat {{ $result->currency }} — unités mineures')
        ->toContain('Aucune conversion de devise n’est appliquée.')
        ->not->toContain('/ 100')
        ->and($funnelStats)->toContain('$result->coverageStart === null')
        ->toContain('Aucune donnée de tunnel calculée pour cette période.');
});

it('adds no migration operation API export or worker execution surface', function () use ($root) {
    $migrations = glob($root.'/database/migrations/*.php') ?: [];
    $surfaceFiles = array_merge(
        glob($root.'/app/Filament/Pages/*.php') ?: [],
        glob($root.'/app/Filament/Widgets/*.php') ?: [],
        glob($root.'/resources/views/filament/widgets/*.blade.php') ?: [],
    );
    $surface = implode("\n", array_map('file_get_contents', $surfaceFiles));

    expect($migrations)->toHaveCount(37)
        ->and(implode("\n", $migrations))->not->toContain('000022')
        ->and($surface)->not->toContain('Artisan::call')
        ->not->toContain('Process::run')
        ->not->toContain('analytics:rollup')
        ->not->toContain('partitions:ensure')
        ->not->toContain('digitrove_analytics_worker')
        ->not->toContain('SQLSTATE')
        ->not->toContain('csv')
        ->not->toContain('export');
});
