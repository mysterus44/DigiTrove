<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Widgets\AnalyticsOverviewStats;
use App\Filament\Widgets\AnalyticsSalesChart;
use App\Filament\Widgets\AnalyticsSalesTable;
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

it('renders the French overview and sales dashboard for an active admin', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
    ]);
    Fixture::funnel('2026-08-03', 10, 8, 6, 4, 3, 2, 1);
    Fixture::sales('2026-08-03', 'XOF', 2, 20_000, 0, 0, 0, 20_000, 10_000);

    $this->actingAs($admin)
        ->get('/admin/analytics')
        ->assertSuccessful()
        ->assertSee('Analytique')
        ->assertSee('Devise');

    $filters = ['from' => '2026-08-03', 'to' => '2026-08-03', 'currency' => 'XOF'];
    Livewire::test(AnalyticsOverviewStats::class, ['pageFilters' => $filters])
        ->assertSee('Vue d’ensemble')
        ->assertSee('Non suivi')
        ->assertSee('Couverture du 2026-08-03 au 2026-08-03')
        ->assertSee('Période en cours');
    Livewire::test(AnalyticsSalesChart::class, ['pageFilters' => $filters])
        ->assertSee('Ventes');
    Livewire::test(AnalyticsSalesTable::class, ['pageFilters' => $filters])
        ->assertSee('Détail des ventes')
        ->assertSee('Réduction')
        ->assertSee('Taxe')
        ->assertSee('Moyenne')
        ->assertSee('20 000');
});

it('keeps the analytics route unavailable to non-admin users', function () {
    $staff = User::factory()->create([
        'role' => UserRole::Staff,
        'status' => UserStatus::Active,
    ]);

    $this->actingAs($staff)->get('/admin/analytics')->assertForbidden();
});

it('fails closed without leaking credential details when the dashboard is disabled', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
    ]);
    config()->set('analytics.dashboard.enabled', false);

    $this->actingAs($admin)
        ->get('/admin/analytics')
        ->assertForbidden()
        ->assertDontSee('ANALYTICS_READER_DB_PASSWORD');
});

it('renders an explicit empty state without querying raw commercial tables', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
    ]);

    $this->actingAs($admin)->get('/admin/analytics')->assertSuccessful();
    Livewire::test(AnalyticsOverviewStats::class, [
        'pageFilters' => ['from' => '2026-08-03', 'to' => '2026-08-03', 'currency' => 'XOF'],
    ])
        ->assertSee('Aucune donnée calculée')
        ->assertDontSee('customer_email')
        ->assertDontSee('order_number');
});
