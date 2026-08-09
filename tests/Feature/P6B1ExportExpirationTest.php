<?php

declare(strict_types=1);

use App\Jobs\GenerateCrmExport;
use App\Services\Crm\CrmExportGenerator;
use App\Services\Crm\CrmExportService;
use Illuminate\Support\Facades\Queue;
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
        'crm.exports.purge_enabled' => true,
    ]);

    Storage::fake('private');
});

function p6b1Expire(int $exportId): void
{
    Fx::owner()->update("UPDATE crm_exports SET expires_at = NOW() - INTERVAL '1 hour' WHERE id = ?", [$exportId]);
}

it('purges an expired export and deletes its artefact', function () {
    Fx::contact();
    $adminId = (int) Admin::admin()->id;

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    app(CrmExportGenerator::class)->generate($export['export_id']);

    $row = app(CrmExportService::class)->get($export['export_id']);
    $disk = (string) $row['storage_disk'];
    $path = (string) $row['storage_path'];

    expect(Storage::disk($disk)->exists($path))->toBeTrue();

    p6b1Expire($export['export_id']);

    test()->artisan('crm:purge-expired-exports')
        ->expectsOutputToContain('expired=1')
        ->expectsOutputToContain('artefacts_deleted=1')
        ->assertSuccessful();

    expect(app(CrmExportService::class)->get($export['export_id'])['status'])->toBe('expired')
        ->and(Storage::disk($disk)->exists($path))->toBeFalse();
});

it('is idempotent: a second purge expires nothing new', function () {
    Fx::contact();
    $adminId = (int) Admin::admin()->id;

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    app(CrmExportGenerator::class)->generate($export['export_id']);
    p6b1Expire($export['export_id']);

    test()->artisan('crm:purge-expired-exports')->expectsOutputToContain('expired=1')->assertSuccessful();
    test()->artisan('crm:purge-expired-exports')->expectsOutputToContain('expired=0')->assertSuccessful();
});

it('leaves an unexpired export completely alone', function () {
    Fx::contact();
    $adminId = (int) Admin::admin()->id;

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    app(CrmExportGenerator::class)->generate($export['export_id']);
    $row = app(CrmExportService::class)->get($export['export_id']);

    test()->artisan('crm:purge-expired-exports')->expectsOutputToContain('expired=0')->assertSuccessful();

    expect(app(CrmExportService::class)->get($export['export_id'])['status'])->toBe('completed')
        ->and(Storage::disk((string) $row['storage_disk'])->exists((string) $row['storage_path']))->toBeTrue();
});

it('does nothing at all when the purge flag is off', function () {
    Fx::contact();
    $adminId = (int) Admin::admin()->id;

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    app(CrmExportGenerator::class)->generate($export['export_id']);
    p6b1Expire($export['export_id']);

    config(['crm.exports.purge_enabled' => false]);

    test()->artisan('crm:purge-expired-exports')
        ->expectsOutputToContain('mode=disabled')
        ->expectsOutputToContain('expired=0')
        ->assertSuccessful();

    // Still completed: the purge is a separate capability from processing.
    expect(app(CrmExportService::class)->get($export['export_id'])['status'])->toBe('completed');
});

it('expires a queued export whose TTL elapsed before it ever ran', function () {
    Fx::contact();
    $adminId = (int) Admin::admin()->id;

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    p6b1Expire($export['export_id']);

    // Claiming an already-expired export must not run it late.
    $status = app(CrmExportGenerator::class)->generate($export['export_id']);

    expect($status)->toBe('expired')
        ->and(app(CrmExportService::class)->get($export['export_id'])['status'])->toBe('expired')
        ->and(Storage::disk('private')->files('crm-exports'))->toBe([]);
});

it('re-dispatches only queued, unexpired exports from the sweeper', function () {
    Queue::fake();

    Fx::contact();
    $adminId = (int) Admin::admin()->id;

    $queued = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    $stale = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    p6b1Expire($stale['export_id']);

    test()->artisan('crm:sweep-exports')
        ->expectsOutputToContain('dispatched=1')
        ->assertSuccessful();

    Queue::assertPushed(
        GenerateCrmExport::class,
        fn (GenerateCrmExport $job): bool => $job->exportId === $queued['export_id'],
    );

    Queue::assertNotPushed(
        GenerateCrmExport::class,
        fn (GenerateCrmExport $job): bool => $job->exportId === $stale['export_id'],
    );
});

it('dispatches nothing when export processing is disabled', function () {
    Queue::fake();

    Fx::contact();
    app(CrmExportService::class)->create('crm_contacts', (int) Admin::admin()->id, null);

    config(['crm.exports.processing_enabled' => false]);

    test()->artisan('crm:sweep-exports')
        ->expectsOutputToContain('mode=disabled')
        ->expectsOutputToContain('dispatched=0')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('rejects an out-of-range limit on both commands', function () {
    foreach (['crm:purge-expired-exports', 'crm:sweep-exports'] as $command) {
        test()->artisan($command, ['--limit' => '0'])->assertFailed();
        test()->artisan($command, ['--limit' => '101'])->assertFailed();
        test()->artisan($command, ['--limit' => 'all'])->assertFailed();
    }
});
