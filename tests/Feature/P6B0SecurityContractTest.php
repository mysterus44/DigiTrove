<?php

declare(strict_types=1);

use Tests\Support\SourceScanner as Scanner;

/**
 * P6-B0 security contract.
 *
 * It scans ONLY the files this gate owns — never a blanket glob that would annex other
 * domains (the mistake P5-A3C made and that this branch corrects). Everything is read
 * as CODE, with comments stripped, so a file may keep explaining what it refuses to do
 * without the explanation itself tripping the guard.
 */

/** @return array{php: list<string>, blade: list<string>} */
function p6b0OwnedFiles(): array
{
    $root = dirname(__DIR__, 2);

    return [
        'php' => [
            $root.'/app/Filament/Pages/CrmContacts.php',
            $root.'/app/Filament/Pages/CrmSegments.php',
            $root.'/app/Filament/Pages/Concerns/AuthorizesCrmAdmin.php',
            $root.'/app/Services/Crm/CrmAdminReadService.php',
            $root.'/app/Support/CrmMoneyPresenter.php',
            $root.'/app/Support/CrmSegmentDefinitionBuilder.php',
            $root.'/app/Support/CrmSegmentDefinitionException.php',
        ],
        'blade' => [
            $root.'/resources/views/filament/pages/crm-contacts.blade.php',
            $root.'/resources/views/filament/pages/crm-segments.blade.php',
        ],
    ];
}

it('scans exactly the files P6-B0 owns and fails closed if one disappears', function () {
    $files = p6b0OwnedFiles();

    expect($files['php'])->toHaveCount(7)
        ->and($files['blade'])->toHaveCount(2);

    // A renamed or deleted file must BREAK this contract, never silently shrink it.
    foreach ([...$files['php'], ...$files['blade']] as $file) {
        expect(is_file($file))->toBeTrue("P6-B0 file is missing: {$file}");
    }
});

it('never reaches a crm_ table directly from the runtime layer', function () {
    $forbidden = [
        'DB::table',
        '::query()',
        'Eloquent',
        'FROM crm_',
        'SELECT * FROM crm_',
        'INSERT INTO crm_',
        'UPDATE crm_',
        'DELETE FROM crm_',
        // The migrator identity must never be used to serve a page.
        'pgsql_migration',
        'digitrove_crm_executor',
    ];

    foreach (p6b0OwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), $forbidden))
            ->toBe([], "forbidden data access in {$file}");
    }
});

it('reads only through the bounded EXECUTE-only authorities', function () {
    $read = Scanner::phpCode(app_path('Services/Crm/CrmAdminReadService.php'));

    // Every call site is a named authority function, never an ad-hoc query.
    foreach ([
        'public.list_crm_contacts(',
        'public.get_crm_contact(',
        'public.find_crm_contact_by_exact_email(',
        'public.list_crm_contact_consent_events(',
        'public.list_crm_contact_commerce_rollups(',
        'public.list_crm_contact_segment_memberships(',
        'public.list_crm_segment_versions(',
    ] as $authority) {
        expect($read)->toContain($authority);
    }
});

it('keeps pagination keyset-bounded with no OFFSET and no fuzzy matching', function () {
    $forbidden = ['OFFSET', 'ILIKE', ' LIKE ', 'similar to', 'levenshtein', 'soundex', 'to_tsquery'];

    foreach (p6b0OwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), $forbidden))
            ->toBe([], "forbidden pagination or matching construct in {$file}");
    }
});

it('offers no raw JSON, code or SQL editor anywhere in the segment builder', function () {
    $files = p6b0OwnedFiles();
    $forbidden = ['<textarea', 'CodeEditor', 'MonacoEditor', 'JsonEditor', 'RichEditor', 'contenteditable'];

    foreach ($files['blade'] as $file) {
        expect(Scanner::violations(Scanner::bladeMarkup($file), $forbidden))
            ->toBe([], "free-form editor present in {$file}");
    }

    // The definition is assembled from closed selects only. NOTE: the strings below are
    // NEEDLES searched for in other files — this test never evaluates or executes them.
    $builder = Scanner::phpCode(app_path('Support/CrmSegmentDefinitionBuilder.php'));
    expect(Scanner::violations($builder, ['eval(', 'DB::raw', 'whereRaw', 'selectRaw', 'unprepared']))->toBe([]);
});

it('sends nothing and integrates with no external provider', function () {
    $forbidden = [
        'Mail::', 'Mailable', 'Notification::', 'Notifiable',
        'Http::', 'GuzzleHttp', 'curl_',
        'ShouldQueue', 'dispatch(',
    ];

    foreach (p6b0OwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), $forbidden))
            ->toBe([], "send or provider surface in {$file}");
    }
});

/**
 * P6-B0 is a READ gate over CRM data plus segment lifecycle. It exports nothing: the
 * audited private export pipeline is P6-B1 and must arrive with its own authority,
 * storage boundary and audit trail — never as an ad-hoc download button here.
 */
it('exposes no export or download surface in B0', function () {
    $files = p6b0OwnedFiles();
    $forbidden = ['csv', 'Csv', 'CSV', 'fputcsv', 'download(', 'streamDownload', 'Storage::', 'response()->file'];

    foreach ($files['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), $forbidden))
            ->toBe([], "export surface in {$file}");
    }

    foreach ($files['blade'] as $file) {
        expect(Scanner::violations(Scanner::bladeMarkup($file), ['csv', 'CSV', 'Exporter', 'Télécharger']))
            ->toBe([], "export affordance in {$file}");
    }
});

it('never computes money and never divides by an assumed currency exponent', function () {
    $forbidden = ['/ 100', '/100', 'floatval', '(float)', 'round(', 'number_format($', 'array_sum'];

    foreach (p6b0OwnedFiles()['php'] as $file) {
        // The presenter groups digits with number_format on an ALREADY absolute integer,
        // which is formatting, not arithmetic — so it is checked separately below.
        if (str_ends_with($file, 'CrmMoneyPresenter.php')) {
            continue;
        }

        expect(Scanner::violations(Scanner::phpCode($file), $forbidden))
            ->toBe([], "monetary computation in {$file}");
    }

    $presenter = Scanner::phpCode(app_path('Support/CrmMoneyPresenter.php'));
    expect(Scanner::violations($presenter, ['/ 100', '/100', 'floatval', 'round(', 'array_sum']))->toBe([])
        // No cross-currency total helper exists at all.
        ->and($presenter)->not->toContain('function total');
});

it('gates every CRM page and action behind the admin authorization trait', function () {
    foreach (['CrmContacts.php', 'CrmSegments.php'] as $page) {
        $code = Scanner::phpCode(app_path('Filament/Pages/'.$page));

        expect($code)->toContain('use AuthorizesCrmAdmin;')
            ->and($code)->toContain('abort_unless(self::canAccess(), 403)')
            // Every mutating or reading action re-checks, never assuming page access.
            ->and($code)->toContain('$this->authorizeCrmAction()');
    }

    $trait = Scanner::phpCode(app_path('Filament/Pages/Concerns/AuthorizesCrmAdmin.php'));
    expect($trait)->toContain("Gate::allows('manageCustomerRelationships')")
        ->and($trait)->toContain('crmFoundationEnabled()');
});

it('registers no public CRM route in the B0 surface', function () {
    foreach (p6b0OwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), ['Route::get', 'Route::post', 'Route::any', 'middleware(']))
            ->toBe([], "route declaration in {$file}");
    }

    // The pages are panel pages: their only path is the admin panel's own prefix.
    expect(Scanner::phpCode(app_path('Filament/Pages/CrmSegments.php')))
        ->toContain("\$routePath = 'crm-segments'");
});
