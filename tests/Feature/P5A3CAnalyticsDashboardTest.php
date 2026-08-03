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

    Livewire::test(AnalyticsProductChart::class, ['pageFilters' => $filters])->assertSee('Produits');
    Livewire::test(AnalyticsProductTable::class, ['pageFilters' => $filters])
        ->assertSee('Produit #10')
        ->assertSee('Vues globales')
        ->assertSee('Achats XOF')
        ->assertSee('Revenu XOF')
        ->assertSee('Moyenne par achat XOF')
        ->assertSee('Non suivi')
        ->assertSee('Les vues sont globales ; les achats et revenus utilisent la devise sélectionnée.');
    Livewire::test(AnalyticsFunnelStats::class, ['pageFilters' => $filters])
        ->assertSee('Tunnel')
        ->assertSee('Ratios agrégés — non cohortés')
        ->assertSee('Ajouts au panier')
        ->assertSee('Non suivi');
    Livewire::test(AnalyticsFunnelChart::class, ['pageFilters' => $filters])->assertSee('Évolution du tunnel');
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
        ->assertSee('Indisponible')
        ->assertSee('1 jour(s) non calculé(s)');

    $chart = app(AnalyticsFunnelChart::class);
    $chart->pageFilters = $filters;
    $data = invade($chart)->getData();
    expect($data['datasets'][0]['data'])->toBe([0, null])
        ->and($data['datasets'][0]['spanGaps'])->toBeFalse();
});
