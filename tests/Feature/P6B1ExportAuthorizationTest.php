<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Pages\CrmExports;
use App\Jobs\GenerateCrmExport;
use App\Models\User;
use App\Services\Crm\CrmExportGenerator;
use App\Services\Crm\CrmExportService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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

it('admits only an active, non-deleted administrator to the exports page', function (
    UserRole $role,
    UserStatus $status,
    bool $deleted,
    bool $allowed,
) {
    $user = User::factory()->create(compact('role', 'status'));

    if ($deleted) {
        $user->delete();
    }

    test()->actingAs($user);

    expect(CrmExports::canAccess())->toBe($allowed)
        // Navigation follows access, never the reverse.
        ->and(CrmExports::shouldRegisterNavigation())->toBe($allowed);
})->with([
    'active admin' => [UserRole::Admin, UserStatus::Active, false, true],
    'active staff' => [UserRole::Staff, UserStatus::Active, false, false],
    'active customer' => [UserRole::Customer, UserStatus::Active, false, false],
    'suspended admin' => [UserRole::Admin, UserStatus::Suspended, false, false],
    'blocked admin' => [UserRole::Admin, UserStatus::Blocked, false, false],
    'deleted admin' => [UserRole::Admin, UserStatus::Active, true, false],
]);

it('closes the page when the exports flag is off even for an admin', function () {
    test()->actingAs(Admin::admin());

    expect(CrmExports::canAccess())->toBeTrue();

    // The export capability has its OWN switch: the CRM foundation being on is not
    // enough to expose an export surface.
    config(['crm.exports.enabled' => false]);
    expect(CrmExports::canAccess())->toBeFalse();

    // A malformed flag closes the door too.
    config(['crm.exports.enabled' => 'yes']);
    expect(CrmExports::canAccess())->toBeFalse();

    // And the CRM foundation flag still gates it independently.
    config(['crm.exports.enabled' => true, 'crm.foundation_enabled' => false]);
    expect(CrmExports::canAccess())->toBeFalse();
});

it('enforces the boundary over HTTP', function () {
    test()->actingAs(Admin::admin())->get('/admin/crm-exports')->assertSuccessful();
    auth()->logout();

    test()->actingAs(Admin::staff())->get('/admin/crm-exports')->assertForbidden();
    auth()->logout();

    test()->actingAs(Admin::customer())->get('/admin/crm-exports')->assertForbidden();
    auth()->logout();

    test()->get('/admin/crm-exports')->assertRedirect();
});

it('requests a contact export and queues it when processing is enabled', function () {
    Queue::fake();
    Fx::contact();
    $admin = Admin::admin();

    test()->actingAs($admin);

    Livewire::test(CrmExports::class)->call('exportContacts');

    $exports = app(CrmExportService::class)->list();

    expect($exports)->toHaveCount(1)
        ->and($exports[0]['kind'])->toBe('crm_contacts')
        ->and($exports[0]['status'])->toBe('queued')
        ->and($exports[0]['requested_by_user_id'])->toBe((int) $admin->id);

    Queue::assertPushed(GenerateCrmExport::class);
});

it('still records a durable export when processing is disabled', function () {
    Queue::fake();
    Fx::contact();

    test()->actingAs(Admin::admin());
    config(['crm.exports.processing_enabled' => false]);

    $component = Livewire::test(CrmExports::class)->call('exportContacts');

    // Durable either way: the row waits rather than the request being lost.
    expect(app(CrmExportService::class)->list())->toHaveCount(1);
    expect($component->get('notice'))->toContain('traitement désactivé');

    Queue::assertNothingPushed();
});

it('refuses a segment export with no segment selected', function () {
    Queue::fake();
    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmExports::class)->call('exportSegmentMembers');

    expect($component->get('notice'))->toBe('Sélectionnez un segment.')
        ->and(app(CrmExportService::class)->list())->toBe([]);

    Queue::assertNothingPushed();
});

it('reports a refused segment export without leaking a database message', function () {
    Queue::fake();
    test()->actingAs(Admin::admin());

    // A segment with no published generation cannot be exported.
    $segmentId = (int) Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', ['Sans génération'])->segment_id;

    $component = Livewire::test(CrmExports::class)
        ->set('segmentId', $segmentId)
        ->call('exportSegmentMembers');

    expect($component->get('notice'))->toBeString()
        ->and($component->get('notice'))->not->toContain('SQLSTATE')
        ->and($component->get('notice'))->not->toContain('crm_exports')
        ->and(app(CrmExportService::class)->list())->toBe([]);
});

it('offers a download link only to the owner of a completed export', function () {
    Fx::contact();
    $owner = Admin::admin();
    $other = Admin::admin();

    test()->actingAs($owner);
    $export = app(CrmExportService::class)->create('crm_contacts', (int) $owner->id, null);
    app(CrmExportGenerator::class)->generate($export['export_id']);

    $row = app(CrmExportService::class)->list()[0];

    expect(Livewire::test(CrmExports::class)->instance()->downloadable($row))->toBeTrue();

    // The same completed row is NOT downloadable for a different admin.
    auth()->logout();
    test()->actingAs($other);
    expect(Livewire::test(CrmExports::class)->instance()->downloadable($row))->toBeFalse();
});
