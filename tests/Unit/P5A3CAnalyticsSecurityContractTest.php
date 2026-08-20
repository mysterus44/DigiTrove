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

/**
 * Tokens that must never appear on the ANALYTICS admin surface. P5-A3D deferred every
 * operation to CLI/scheduler, so an analytics screen that could trigger a rollup, name
 * the worker role, leak a SQLSTATE or offer an export/CSV download would silently
 * re-open a boundary D-042 closed.
 *
 * @return list<string>
 */
function p5a3cForbiddenAnalyticsTokens(): array
{
    return [
        'Artisan::call',
        'Process::run',
        'analytics:rollup',
        'partitions:ensure',
        'digitrove_analytics_worker',
        'SQLSTATE',
        'csv',
        'export',
    ];
}

/**
 * @return list<string> the forbidden tokens actually present in $content
 */
function p5a3cViolations(string $content): array
{
    return array_values(array_filter(
        p5a3cForbiddenAnalyticsTokens(),
        static fn (string $token): bool => str_contains($content, $token),
    ));
}

/**
 * THE canonical enumeration of the analytics admin surface.
 *
 * It is deliberately EXPLICIT. The previous version globbed `app/Filament/Pages/*.php`,
 * which annexed every page of every other domain: a CRM export page would have broken
 * this analytics contract for a reason that has nothing to do with analytics. The fix
 * is to AIM the guard, not to weaken it — there is no "ignore CRM" exclusion here, and
 * a new analytics page/widget must be added to this list to be covered.
 *
 * @return list<string>
 */
function p5a3cAnalyticsSurfaceFiles(string $root): array
{
    return array_merge(
        [$root.'/app/Filament/Pages/AnalyticsDashboard.php'],
        glob($root.'/app/Filament/Widgets/Analytics*.php') ?: [],
        glob($root.'/resources/views/filament/widgets/analytics-*.blade.php') ?: [],
    );
}

it('adds no migration operation API export or worker execution surface', function () use ($root) {
    $migrations = glob($root.'/database/migrations/*.php') ?: [];
    $surfaceFiles = p5a3cAnalyticsSurfaceFiles($root);

    // Fail closed: a renamed or deleted analytics file must BREAK this test rather than
    // silently shrink the scanned surface to nothing and report a vacuous pass.
    expect($surfaceFiles)->toHaveCount(11);

    foreach ($surfaceFiles as $file) {
        expect(is_file($file))->toBeTrue("analytics surface file is missing: {$file}");
    }

    $surface = implode("\n", array_map('file_get_contents', $surfaceFiles));

    expect($migrations)->toHaveCount(53)
        ->and(glob($root.'/database/migrations/2026_07_14_000038*.php') ?: [])->toBe([])
        ->and(p5a3cViolations($surface))->toBe([]);
});

it('still rejects an analytics surface that gained an export or operation affordance', function () {
    // The guard keeps its teeth: these are the exact shapes it must catch, proven
    // against synthetic content so the proof does not depend on the repository ever
    // being wrong.
    expect(p5a3cViolations('<x-filament::button wire:click="export">CSV</x-filament::button>'))
        ->toBe(['export'])
        ->and(p5a3cViolations('Storage::disk("local")->put($csvPath, $rows);'))->toBe(['csv'])
        ->and(p5a3cViolations('Artisan::call("analytics:rollup");'))
        ->toBe(['Artisan::call', 'analytics:rollup'])
        ->and(p5a3cViolations('the raw SQLSTATE was rendered'))->toBe(['SQLSTATE'])
        ->and(p5a3cViolations('a clean analytics widget'))->toBe([]);
});

it('scopes the analytics contract to analytics files only, never to CRM pages', function () use ($root) {
    $surfaceFiles = p5a3cAnalyticsSurfaceFiles($root);
    $crmPages = glob($root.'/app/Filament/Pages/Crm*.php') ?: [];

    // CRM pages exist…
    expect($crmPages)->not->toBe([]);

    // …and none of them is part of the analytics surface. P6-B1 will legitimately add a
    // CRM export page; that must never be judged by the ANALYTICS no-export rule.
    foreach ($crmPages as $crmPage) {
        expect($surfaceFiles)->not->toContain($crmPage);
    }
});
