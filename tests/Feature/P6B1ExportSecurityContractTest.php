<?php

declare(strict_types=1);

use Tests\Support\SourceScanner as Scanner;

/**
 * P6-B1 security contract. Scans ONLY the files this gate owns, as CODE (comments
 * stripped), so a file may keep documenting what it refuses to do.
 */

/** @return array{php: list<string>, blade: list<string>} */
function p6b1OwnedFiles(): array
{
    $root = dirname(__DIR__, 2);

    return [
        'php' => [
            $root.'/app/Console/Commands/PurgeExpiredCrmExports.php',
            $root.'/app/Console/Commands/SweepCrmExports.php',
            $root.'/app/Filament/Pages/CrmExports.php',
            $root.'/app/Http/Controllers/CrmExportDownloadController.php',
            $root.'/app/Jobs/GenerateCrmExport.php',
            $root.'/app/Services/Crm/CrmExportGenerator.php',
            $root.'/app/Services/Crm/CrmExportService.php',
            $root.'/app/Support/CrmExportCsvWriter.php',
        ],
        'blade' => [
            $root.'/resources/views/filament/pages/crm-exports.blade.php',
        ],
    ];
}

it('scans exactly the files P6-B1 owns and fails closed if one disappears', function () {
    $files = p6b1OwnedFiles();

    expect($files['php'])->toHaveCount(8)
        ->and($files['blade'])->toHaveCount(1);

    foreach ([...$files['php'], ...$files['blade']] as $file) {
        expect(is_file($file))->toBeTrue("P6-B1 file is missing: {$file}");
    }
});

it('never touches crm_exports or any crm_ table directly', function () {
    $forbidden = [
        'DB::table', '::query()', 'Eloquent',
        'FROM crm_', 'INSERT INTO crm_', 'UPDATE crm_', 'DELETE FROM crm_',
        'pgsql_migration', 'digitrove_crm_executor',
    ];

    foreach (p6b1OwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), $forbidden))
            ->toBe([], "forbidden data access in {$file}");
    }
});

it('reads and writes only through the bounded EXECUTE-only authorities', function () {
    $service = Scanner::phpCode(app_path('Services/Crm/CrmExportService.php'));

    foreach ([
        'public.create_crm_export(', 'public.claim_crm_export(', 'public.complete_crm_export(',
        'public.fail_crm_export(', 'public.get_crm_export(', 'public.list_crm_exports(',
        'public.list_due_crm_exports(', 'public.expire_crm_exports(',
        'public.list_crm_export_contact_rows(', 'public.list_crm_export_member_rows(',
    ] as $authority) {
        expect($service)->toContain($authority);
    }
});

it('keeps pagination keyset-bounded with no OFFSET', function () {
    foreach (p6b1OwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), ['OFFSET', 'ILIKE', ' LIKE ']))
            ->toBe([], "forbidden pagination construct in {$file}");
    }
});

/**
 * The job payload is the whole confidentiality story: whatever it carries is written to
 * Redis, `jobs`, `failed_jobs` and any serialized failure record.
 */
it('carries an id and nothing else through the queue', function () {
    $job = Scanner::phpCode(app_path('Jobs/GenerateCrmExport.php'));

    expect($job)->toContain('public readonly int $exportId')
        ->and($job)->toContain('ShouldBeUnique')
        ->and($job)->toContain('ShouldQueue');

    foreach (['email', 'storage_path', 'checksum', 'csv', '$rows', 'getMessage'] as $token) {
        expect(Scanner::violations($job, [$token]))->toBe([], "queue payload leak: {$token}");
    }
});

it('never leaks a storage path, SQLSTATE or raw exception to a screen', function () {
    $page = Scanner::phpCode(app_path('Filament/Pages/CrmExports.php'));
    $view = Scanner::bladeMarkup(resource_path('views/filament/pages/crm-exports.blade.php'));

    foreach (['getMessage()', 'QueryException', 'SQLSTATE', 'getTraceAsString', 'storage_path', 'storage_disk', 'checksum'] as $token) {
        expect(Scanner::violations($page, [$token]))->toBe([], "leak in the exports page: {$token}");
        expect(Scanner::violations($view, [$token]))->toBe([], "leak in the exports view: {$token}");
    }
});

it('offers no public URL, signed URL or bearer path to an artefact', function () {
    $controller = Scanner::phpCode(app_path('Http/Controllers/CrmExportDownloadController.php'));
    $page = Scanner::phpCode(app_path('Filament/Pages/CrmExports.php'));

    foreach (['temporaryUrl', 'signedRoute', 'signedUrl', '->url(', 'Bearer', 'public_path'] as $token) {
        expect(Scanner::violations($controller, [$token]))->toBe([], "public access affordance: {$token}");
        expect(Scanner::violations($page, [$token]))->toBe([], "public access affordance: {$token}");
    }

    // Ownership is enforced, and refusal is a flat 404 rather than a distinguishable 403.
    expect($controller)->toContain('requested_by_user_id')
        ->and($controller)->toContain('404')
        ->and($controller)->not->toContain('403');
});

it('sends nothing and integrates with no external provider', function () {
    $forbidden = ['Mail::', 'Mailable', 'Notification::', 'Http::', 'GuzzleHttp', 'curl_'];

    foreach (p6b1OwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), $forbidden))
            ->toBe([], "send or provider surface in {$file}");
    }
});

it('produces CSV only, never another serialisation format', function () {
    foreach (p6b1OwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), ['xlsx', 'Xlsx', 'PhpSpreadsheet', 'toJson(', '->pdf']))
            ->toBe([], "extra export format in {$file}");
    }
});

it('keeps file I/O out of database transactions', function () {
    $generator = Scanner::phpCode(app_path('Services/Crm/CrmExportGenerator.php'));

    // The guard is structural, not a convention the caller has to remember.
    expect($generator)->toContain('assertOutsideTransaction')
        ->and($generator)->toContain('DB::transactionLevel() !== 0')
        // No transaction is ever opened around the write path.
        ->and($generator)->not->toContain('DB::transaction(')
        ->and($generator)->not->toContain('beginTransaction');
});

it('keeps every export feature flag off by default', function () {
    $config = require base_path('config/crm.php');

    // env() defaults are what a fresh deployment gets.
    expect($config['exports']['enabled'])->toBeFalse()
        ->and($config['exports']['processing_enabled'])->toBeFalse()
        ->and($config['exports']['purge_enabled'])->toBeFalse();
});
