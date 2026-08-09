<?php

declare(strict_types=1);

use App\Filament\Pages\CrmContacts;
use App\Filament\Pages\CrmSegments;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

/**
 * P6-B0 adds admin PAGES, never a public surface. Any CRM route that is reachable
 * without the admin panel's authentication middleware would expose customer identity
 * data to the internet.
 */
it('registers no CRM route outside the authenticated admin panel', function () {
    $crmRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains(strtolower($route->uri()), 'crm'))
        ->values();

    expect($crmRoutes)->not->toBeEmpty();

    foreach ($crmRoutes as $route) {
        // Every CRM URI lives under the admin panel prefix…
        expect($route->uri())->toStartWith('admin/');

        // …and carries the panel's authentication middleware.
        $middleware = implode(' ', $route->gatherMiddleware());
        expect($middleware)->toContain('Filament\Http\Middleware\Authenticate');
    }
});

it('refuses every CRM page to guests, staff and customers over HTTP', function () {
    foreach (['/admin/crm-contacts', '/admin/crm-segments'] as $uri) {
        test()->actingAs(Admin::staff())->get($uri)->assertForbidden();
        auth()->logout();

        test()->actingAs(Admin::customer())->get($uri)->assertForbidden();
        auth()->logout();

        // A guest is redirected to login and never served CRM data.
        test()->get($uri)->assertRedirect();
    }
});

it('serves both CRM pages to an active administrator', function () {
    Fx::contact();

    $admin = Admin::admin();

    test()->actingAs($admin)->get('/admin/crm-contacts')->assertSuccessful();
    test()->actingAs($admin)->get('/admin/crm-segments')->assertSuccessful();
});

it('hides CRM navigation and access when the foundation flag is off', function () {
    test()->actingAs(Admin::admin());

    config(['crm.foundation_enabled' => false]);

    expect(CrmContacts::canAccess())->toBeFalse()
        ->and(CrmSegments::canAccess())->toBeFalse()
        ->and(CrmContacts::shouldRegisterNavigation())->toBeFalse()
        ->and(CrmSegments::shouldRegisterNavigation())->toBeFalse();

    // Navigation follows access; the HTTP boundary closes with it.
    test()->get('/admin/crm-segments')->assertForbidden();
});

it('exposes no CRM API, JSON or webhook endpoint', function () {
    $uris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => strtolower($route->uri()))
        ->filter(fn (string $uri): bool => str_contains($uri, 'crm'))
        ->values();

    foreach ($uris as $uri) {
        expect($uri)->not->toStartWith('api/')
            ->and($uri)->not->toContain('webhook')
            ->and($uri)->not->toContain('.json');
    }
});
