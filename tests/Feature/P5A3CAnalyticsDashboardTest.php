<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Widgets\AnalyticsFunnelChart;
use App\Filament\Widgets\AnalyticsFunnelStats;
use App\Filament\Widgets\AnalyticsProductChart;
use App\Filament\Widgets\AnalyticsProductTable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\P5A3DashboardFixture as Fixture;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03 12:00:00', 'UTC'));
    Fixture::clear();
});

afterEach(function () {
    Fixture::clear();
    CarbonImmutable::setTestNow();
});

it('renders the product and funnel views for an active administrator', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    Fixture::funnel('2026-08-03', 10, 8, 12, 9, 4, 2, 1);
    Fixture::productEngagement('2026-08-03', 10, 12);
    Fixture::productSales('2026-08-03', 10, 'XOF', 2, 2_000);
    $filters = ['from' => '2026-08-03', 'to' => '2026-08-03', 'currency' => 'XOF', 'product_sort' => 'revenue_desc'];

    $this->actingAs($admin)
        ->get('/admin/analytics')
        ->assertSuccessful()
        ->assertSee('Classement');

    $productChart = app(AnalyticsProductChart::class);
    $productChart->pageFilters = $filters;
    $productChartData = invade($productChart)->getData();

    Livewire::test(AnalyticsProductChart::class, ['pageFilters' => $filters])
        ->assertSee('Produits')
        ->assertSee('Revenu XOF — unités mineures');
    expect($productChartData['datasets'][0]['data'])->toBe([2_000]);
    Livewire::test(AnalyticsProductTable::class, ['pageFilters' => $filters])
        ->assertSee('Produit #10')
        ->assertSee('Vues globales')
        ->assertSee('Achats XOF')
        ->assertSee('Revenu XOF — unités mineures')
        ->assertSee('Moyenne par achat XOF — unités mineures')
        ->assertSee('Non suivi')
        ->assertSee('Les vues sont globales ; les achats et revenus utilisent la devise sélectionnée.')
        ->assertSee('Les montants sont affichés en unités mineures de la devise sélectionnée.')
        ->assertSee('Aucune conversion de devise n’est appliquée.');
    Livewire::test(AnalyticsFunnelStats::class, ['pageFilters' => $filters])
        ->assertSee('Tunnel')
        ->assertSee('Ratios agrégés — non cohortés')
        ->assertSee('Ajouts au panier')
        ->assertSee('Non suivi');
    Livewire::test(AnalyticsFunnelChart::class, ['pageFilters' => $filters])->assertSee('Évolution du tunnel');
});

it('charts product identifiers as an ordering dimension and never as a metric', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    Fixture::funnel('2026-08-01', 1, 1, 1, 0, 0, 0, 0);
    Fixture::productEngagement('2026-08-01', 2, 90);
    Fixture::productEngagement('2026-08-01', 100, 3);
    Fixture::productSales('2026-08-01', 2, 'XOF', 1, 100);
    Fixture::productSales('2026-08-01', 100, 'XOF', 2, 500);
    $this->actingAs($admin);

    $chartData = function (string $sort): array {
        $chart = app(AnalyticsProductChart::class);
        $chart->pageFilters = [
            'from' => '2026-08-01',
            'to' => '2026-08-01',
            'currency' => 'XOF',
            'product_sort' => $sort,
        ];

        return [invade($chart)->getData(), $chart->getDescription()];
    };

    [$byId, $byIdDescription] = $chartData('product_id_asc');
    [$byRevenue, $byRevenueDescription] = $chartData('revenue_desc');
    [$byPurchases] = $chartData('purchases_desc');
    [$byViews] = $chartData('views_desc');

    expect($byId['labels'])->toBe(['Produit #2', 'Produit #100'])
        ->and($byId['datasets'][0]['data'])->toBe([90, 3])
        ->not->toBe([2, 100])
        ->and($byId['datasets'][0]['label'])->toBe('Vues globales — tri par identifiant')
        ->and($byIdDescription)->toBe('10 premiers identifiants produit, avec leurs vues globales.')
        ->not->toContain('Top 10')
        ->and($byRevenue['datasets'][0]['data'])->toBe([500, 100])
        ->and($byRevenue['datasets'][0]['label'])->toBe('Revenu XOF — unités mineures')
        ->and($byRevenueDescription)->toBe('Top 10 produits selon la métrique sélectionnée.')
        ->and($byPurchases['datasets'][0]['data'])->toBe([2, 1])
        ->and($byViews['datasets'][0]['data'])->toBe([90, 3]);
});

it('keeps the existing admin-only boundary for the extended analytics page', function (UserRole $role, UserStatus $status) {
    $user = User::factory()->create(compact('role', 'status'));

    $this->actingAs($user)->get('/admin/analytics')->assertForbidden();
})->with([
    'staff' => [UserRole::Staff, UserStatus::Active],
    'customer' => [UserRole::Customer, UserStatus::Active],
    'suspended admin' => [UserRole::Admin, UserStatus::Suspended],
    'blocked admin' => [UserRole::Admin, UserStatus::Blocked],
]);

it('renders explicit unavailable values and preserves funnel gaps', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    Fixture::funnel('2026-08-01', 0, 0, 0, 0, 0, 0, 0);
    $this->actingAs($admin);
    $filters = ['from' => '2026-08-01', 'to' => '2026-08-02', 'currency' => 'XOF', 'product_sort' => 'views_desc'];

    Livewire::test(AnalyticsProductTable::class, ['pageFilters' => $filters])
        ->assertSee('Aucune donnée produit calculée');
    Livewire::test(AnalyticsFunnelStats::class, ['pageFilters' => $filters])
        ->assertSee('Visiteurs')
        ->assertSee('Sessions')
        ->assertSee('0')
        ->assertSee('Indisponible')
        ->assertSee('1 jour(s) non calculé(s)');

    $chart = app(AnalyticsFunnelChart::class);
    $chart->pageFilters = $filters;
    $data = invade($chart)->getData();
    expect($data['datasets'][0]['data'])->toBe([0, null])
        ->and($data['datasets'][0]['spanGaps'])->toBeFalse();
});

it('renders an uncovered funnel period without presenting implicit zero measurements', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    $this->actingAs($admin);
    $filters = ['from' => '2026-08-01', 'to' => '2026-08-02', 'currency' => 'XOF', 'product_sort' => 'views_desc'];

    Livewire::test(AnalyticsFunnelStats::class, ['pageFilters' => $filters])
        ->assertSee('Aucune donnée de tunnel calculée pour cette période.')
        ->assertDontSee('Visiteurs')
        ->assertDontSee('Sessions')
        ->assertDontSee('Ratios agrégés — non cohortés')
        ->assertDontSee('Indisponible');

    $chart = app(AnalyticsFunnelChart::class);
    $chart->pageFilters = $filters;
    $data = invade($chart)->getData();

    expect($data['labels'])->toBe(['2026-08-01', '2026-08-02']);
    foreach ($data['datasets'] as $dataset) {
        expect($dataset['data'])->toBe([null, null])
            ->and($dataset['spanGaps'])->toBeFalse();
    }
});
