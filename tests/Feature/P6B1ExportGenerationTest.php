<?php

declare(strict_types=1);

use App\Services\Crm\CrmExportGenerator;
use App\Services\Crm\CrmExportService;
use App\Services\Crm\CrmOperationException;
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
        'crm.exports.max_rows' => 10000,
        'crm.exports.ttl_hours' => 24,
    ]);

    // Isolate the artefacts. Without this every test writes into the developer's real
    // `storage/app/private`, and a later assertion sees files left by an earlier test —
    // exactly how the first run of this file produced a false failure.
    //
    // The REAL private-disk configuration is asserted in P6B1ExportSchemaTest, where it
    // is not shadowed by the fake.
    Storage::fake('private');
});

/** @return array{0:string,1:string} the disk and path of a completed export */
function p6b1Artefact(int $exportId): array
{
    $export = app(CrmExportService::class)->get($exportId);

    return [(string) $export['storage_disk'], (string) $export['storage_path']];
}

it('writes a contact export with a header and one row per contact', function () {
    $a = Fx::contact('active', 'guest_order');
    $b = Fx::contact('active', 'verified_account');
    $adminId = (int) Admin::admin()->id;

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    $status = app(CrmExportGenerator::class)->generate($export['export_id']);

    expect($status)->toBe('completed');

    $row = app(CrmExportService::class)->get($export['export_id']);
    expect($row['row_count'])->toBe(2)
        ->and($row['size_bytes'])->toBeGreaterThan(0)
        ->and($row['checksum_sha256'])->toMatch('/\A[0-9a-f]{64}\z/');

    [$disk, $path] = p6b1Artefact($export['export_id']);
    $csv = Storage::disk($disk)->get($path);

    expect($csv)->toContain('contact_id,public_id,email,status,origin,created_at')
        ->and($csv)->toContain(Admin::emailOf($a))
        ->and($csv)->toContain(Admin::emailOf($b));
});

it('writes a NULL e-mail for an anonymized contact rather than inventing one', function () {
    Fx::contact('anonymized', 'verified_account');
    $adminId = (int) Admin::admin()->id;

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    app(CrmExportGenerator::class)->generate($export['export_id']);

    [$disk, $path] = p6b1Artefact($export['export_id']);
    $csv = Storage::disk($disk)->get($path);

    // The address is physically absent, so the field is empty — never "NULL", never a
    // mask, never a reconstructed value.
    expect($csv)->toContain('anonymized')
        ->and($csv)->not->toContain('@example.com')
        ->and($csv)->not->toContain('NULL');
});

/**
 * THE core B1 invariant. An export freezes the generation at creation; publishing a new
 * generation mid-flight must not leak a single row of it into the file.
 */
it('keeps a member export bound to the generation frozen at creation', function () {
    $first = Fx::contact();
    Fx::rollup($first, 'XOF', gross: 50_000);

    $definition = Fx::definition([
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1_000,
    ]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);
    $g1 = Fx::buildGeneration($segmentId);

    $adminId = (int) Admin::admin()->id;
    $export = app(CrmExportService::class)->create('segment_current_members', $adminId, $segmentId);

    expect($export['generation_id'])->toBe($g1);

    // A second qualifying contact appears and G2 is published BEFORE the file is written.
    $second = Fx::contact();
    Fx::rollup($second, 'XOF', gross: 90_000);
    $g2 = Fx::buildGeneration($segmentId);

    expect($g2)->not->toBe($g1);

    app(CrmExportGenerator::class)->generate($export['export_id']);

    $row = app(CrmExportService::class)->get($export['export_id']);
    [$disk, $path] = p6b1Artefact($export['export_id']);
    $csv = Storage::disk($disk)->get($path);

    // 100 % G1: exactly one member, and it is the one G1 contained.
    expect($row['row_count'])->toBe(1)
        ->and($row['generation_id'])->toBe($g1)
        ->and($csv)->toContain(Admin::emailOf($first))
        ->and($csv)->not->toContain(Admin::emailOf($second));
});

it('fails with row_limit_exceeded and publishes no file on overflow', function () {
    foreach (range(1, 3) as $ignored) {
        Fx::contact();
    }

    $adminId = (int) Admin::admin()->id;
    config(['crm.exports.max_rows' => 2]);

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    $status = app(CrmExportGenerator::class)->generate($export['export_id']);

    expect($status)->toBe('failed');

    $row = app(CrmExportService::class)->get($export['export_id']);

    expect($row['status'])->toBe('failed')
        ->and($row['terminal_reason'])->toBe('row_limit_exceeded')
        // No silent truncation: there is no artefact at all.
        ->and($row['storage_path'])->toBeNull()
        ->and($row['row_count'])->toBeNull();

    expect(Storage::disk(config('crm.exports.disk', 'private'))->files('crm-exports'))->toBe([]);
});

it('claims an export exactly once so a second worker produces no second file', function () {
    Fx::contact();
    $adminId = (int) Admin::admin()->id;

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);

    expect(app(CrmExportGenerator::class)->generate($export['export_id']))->toBe('completed');

    // A re-run finds the export already completed and does nothing.
    expect(app(CrmExportGenerator::class)->generate($export['export_id']))->toBe('completed');

    expect(Storage::disk(config('crm.exports.disk', 'private'))->files('crm-exports'))->toHaveCount(1);
});

it('stores the artefact on a private disk under a path carrying no PII', function () {
    $contactId = Fx::contact();
    $email = Admin::emailOf($contactId);
    $adminId = (int) Admin::admin()->id;

    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);
    app(CrmExportGenerator::class)->generate($export['export_id']);

    [$disk, $path] = p6b1Artefact($export['export_id']);

    expect($disk)->toBe('private')
        ->and($path)->toStartWith('crm-exports/')
        ->and($path)->toEndWith('.csv')
        // No address, no user id, no segment name in the path.
        ->and($path)->not->toContain($email)
        ->and($path)->not->toContain((string) $adminId.'-'.$adminId);

    // Traversal-proof: no absolute path, no '..', no backslash.
    expect($path)->not->toContain('..')
        ->and($path)->not->toContain('\\')
        ->and(str_starts_with($path, '/'))->toBeFalse();
});

it('refuses to generate while inside a database transaction', function () {
    Fx::contact();
    $adminId = (int) Admin::admin()->id;
    $export = app(CrmExportService::class)->create('crm_contacts', $adminId, null);

    // Holding a PostgreSQL transaction open across disk I/O pins a snapshot and a
    // connection for the whole export; the guard refuses rather than trusting callers.
    DB::beginTransaction();

    try {
        expect(fn () => app(CrmExportGenerator::class)->generate($export['export_id']))
            ->toThrow(RuntimeException::class, 'outside a database transaction');
    } finally {
        DB::rollBack();
    }
});

it('refuses every authority call when the exports flag is off', function () {
    $adminId = (int) Admin::admin()->id;
    config(['crm.exports.enabled' => false]);

    expect(fn () => app(CrmExportService::class)->create('crm_contacts', $adminId, null))
        ->toThrow(CrmOperationException::class);
});
