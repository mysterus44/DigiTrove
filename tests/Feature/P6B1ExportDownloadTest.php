<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Crm\CrmExportGenerator;
use App\Services\Crm\CrmExportService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.exports.enabled' => true,
        'crm.exports.processing_enabled' => true,
    ]);

    Storage::fake('private');
});

/** Create a completed export owned by $owner and return its id. */
function p6b1CompletedExport(User $owner): int
{
    Fx::contact();

    $export = app(CrmExportService::class)->create('crm_contacts', (int) $owner->id, null);
    app(CrmExportGenerator::class)->generate($export['export_id']);

    return $export['export_id'];
}

function p6b1DownloadUri(int $exportId): string
{
    return '/admin/crm-exports/'.$exportId.'/download';
}

it('serves a completed export to the admin who requested it', function () {
    $admin = Admin::admin();
    $exportId = p6b1CompletedExport($admin);

    $response = test()->actingAs($admin)->get(p6b1DownloadUri($exportId));

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Symfony normalises and reorders Cache-Control directives, so the DIRECTIVES are
    // asserted rather than the literal string an exact match would pin.
    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('private')
        ->and($cacheControl)->not->toContain('public');

    expect($response->headers->get('Content-Disposition'))->toContain('attachment;')
        // The filename is derived from the public id: the storage path never leaks.
        ->and($response->headers->get('Content-Disposition'))->not->toContain('crm-exports/');

    expect($response->streamedContent())->toContain('contact_id,public_id,email,status,origin,created_at');
});

/**
 * The artefact is a nominative, audited record. Another admin — equally privileged — is
 * still refused, and refused with the SAME flat 404 as a non-existent export, so the
 * response cannot be used to discover that someone else's export exists.
 */
it('refuses the export of another admin with the same flat 404', function () {
    $owner = Admin::admin();
    $otherAdmin = Admin::admin();
    $exportId = p6b1CompletedExport($owner);

    $foreign = test()->actingAs($otherAdmin)->get(p6b1DownloadUri($exportId));
    $missing = test()->actingAs($otherAdmin)->get(p6b1DownloadUri(999_999));

    $foreign->assertNotFound();
    $missing->assertNotFound();

    // Indistinguishable: same status AND same body.
    expect($foreign->getContent())->toBe($missing->getContent());
});

/**
 * TWO LAYERS, two deliberately different answers.
 *
 * Panel level: staff and customers cannot enter the admin panel at all, so Filament's
 * own middleware answers 403 — uniformly, for every panel URL. That is not an oracle:
 * it says nothing about this export, and `/admin/crm-contacts` answers identically.
 *
 * Export level: a user who CAN enter the panel must never learn whether another admin's
 * export exists, so that refusal is the flat 404 asserted above.
 */
it('refuses staff and customers at the panel boundary, and guests at the login boundary', function () {
    $admin = Admin::admin();
    $exportId = p6b1CompletedExport($admin);

    test()->actingAs(Admin::staff())->get(p6b1DownloadUri($exportId))->assertForbidden();
    auth()->logout();

    test()->actingAs(Admin::customer())->get(p6b1DownloadUri($exportId))->assertForbidden();
    auth()->logout();

    // The refusal is panel-wide, not export-specific: an ordinary CRM page answers the
    // same way, so the status reveals nothing about the export.
    test()->actingAs(Admin::staff())->get('/admin/crm-contacts')->assertForbidden();
    auth()->logout();

    // A guest never reaches the controller: the panel's auth middleware redirects.
    test()->get(p6b1DownloadUri($exportId))->assertRedirect();
});

it('refuses a queued, running or failed export', function () {
    $admin = Admin::admin();
    Fx::contact();

    // Queued: never generated.
    $queued = app(CrmExportService::class)->create('crm_contacts', (int) $admin->id, null);
    test()->actingAs($admin)->get(p6b1DownloadUri($queued['export_id']))->assertNotFound();

    // Failed via the overflow path.
    config(['crm.exports.max_rows' => 1]);
    Fx::contact();
    Fx::contact();
    $failed = app(CrmExportService::class)->create('crm_contacts', (int) $admin->id, null);
    app(CrmExportGenerator::class)->generate($failed['export_id']);

    expect(app(CrmExportService::class)->get($failed['export_id'])['status'])->toBe('failed');
    test()->actingAs($admin)->get(p6b1DownloadUri($failed['export_id']))->assertNotFound();
});

it('refuses an expired export even though the file may still exist', function () {
    $admin = Admin::admin();
    $exportId = p6b1CompletedExport($admin);

    // Push the TTL into the past through the owner connection.
    Fx::owner()->update("UPDATE crm_exports SET expires_at = NOW() - INTERVAL '1 hour' WHERE id = ?", [$exportId]);

    test()->actingAs($admin)->get(p6b1DownloadUri($exportId))->assertNotFound();
});

it('refuses every download when the exports flag is off', function () {
    $admin = Admin::admin();
    $exportId = p6b1CompletedExport($admin);

    config(['crm.exports.enabled' => false]);
    test()->actingAs($admin)->get(p6b1DownloadUri($exportId))->assertNotFound();

    // A malformed flag closes the door too.
    config(['crm.exports.enabled' => 'yes']);
    test()->actingAs($admin)->get(p6b1DownloadUri($exportId))->assertNotFound();
});

it('refuses when the artefact is missing from disk', function () {
    $admin = Admin::admin();
    $exportId = p6b1CompletedExport($admin);
    $row = app(CrmExportService::class)->get($exportId);

    Storage::disk((string) $row['storage_disk'])->delete((string) $row['storage_path']);

    // A completed row pointing at a vanished file must not 500.
    test()->actingAs($admin)->get(p6b1DownloadUri($exportId))->assertNotFound();
});

it('exposes no public, signed or bearer route for an export', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains(strtolower($route->uri()), 'crm-export'))
        ->values();

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        expect($route->uri())->toStartWith('admin/');

        $middleware = implode(' ', $route->gatherMiddleware());
        expect($middleware)->toContain('Filament\Http\Middleware\Authenticate');

        // No signed-URL middleware anywhere: access is a per-request server decision.
        expect($middleware)->not->toContain('signed');
    }

    // The historical contract holds: no CRM path on the public router.
    $web = file_get_contents(base_path('routes/web.php')).file_get_contents(base_path('routes/api.php'));
    expect(strtolower($web))->not->toContain('crm');
});
