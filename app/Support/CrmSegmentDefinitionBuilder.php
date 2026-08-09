<?php

declare(strict_types=1);

namespace App\Support;

/**
 * P6-B0 structured criterion builder.
 *
 * It converts ALLOWLISTED admin form rows into a DSL V1 envelope and nothing else.
 * There is deliberately no way to express a raw JSON blob, a column name, a SQL
 * fragment, an arbitrary field or an arbitrary operator: every branch below is a
 * closed `match`, so an unknown token cannot fall through — it raises.
 *
 * WHY THIS EXISTS AT ALL, given PostgreSQL already validates:
 * `validate_crm_segment_definition_v1` remains THE authority and refuses anything this
 * class would wrongly emit. This builder is a *shaping* layer, not a security boundary
 * — its job is to make the admin UI structurally incapable of composing a definition
 * the authority would reject, so operators get a field-level message instead of an
 * opaque refusal. If the two ever disagree, PostgreSQL wins and the version is not
 * created.
 *
 * The emitted key sets mirror the authority's EXACT expectations. An extra key — even a
 * harmless-looking one — makes the whole definition invalid there, so the arrays below
 * are built key-by-key rather than merged from user input.
 */
final class CrmSegmentDefinitionBuilder
{
    /** Closed field allowlist, mirroring the authority's CASE. */
    public const FIELDS = [
        'commerce.net_revenue_minor' => 'commerce_number',
        'commerce.gross_revenue_minor' => 'commerce_number',
        'commerce.refunded_amount_minor' => 'commerce_number',
        'commerce.acquired_orders_count' => 'commerce_number',
        'commerce.first_acquired_at' => 'commerce_date',
        'commerce.last_acquired_at' => 'commerce_date',
        'contact.created_at' => 'contact_date',
        'contact.status' => 'contact_enum',
        'contact.origin' => 'contact_enum',
    ];

    public const NUMERIC_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between'];

    public const DATE_OPERATORS = ['before', 'after', 'between'];

    public const ENUM_OPERATORS = ['in', 'not_in'];

    public const MATCH_MODES = ['all', 'any'];

    /** Real repository values only — the crm_contacts CHECK constraints. */
    public const ENUM_VALUES = [
        'contact.status' => ['active', 'anonymized'],
        'contact.origin' => ['guest_order', 'verified_account'],
    ];

    public const MAX_CRITERIA = 50;

    /**
     * Build a complete DSL V1 definition.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{schema_version:int,match:string,criteria:list<array<string,mixed>>}
     *
     * @throws CrmSegmentDefinitionException
     */
    public static function build(string $match, array $rows): array
    {
        if (! in_array($match, self::MATCH_MODES, true)) {
            throw CrmSegmentDefinitionException::because('invalid_match_mode');
        }

        if ($rows === []) {
            throw CrmSegmentDefinitionException::because('criteria_required');
        }

        if (count($rows) > self::MAX_CRITERIA) {
            throw CrmSegmentDefinitionException::because('too_many_criteria');
        }

        $criteria = [];

        foreach ($rows as $row) {
            $criteria[] = self::criterion($row);
        }

        // Exactly three top-level keys, in the shape the authority demands.
        return [
            'schema_version' => 1,
            'match' => $match,
            'criteria' => $criteria,
        ];
    }

    /**
     * Field => kind, for the UI to decide which inputs to render.
     *
     * @throws CrmSegmentDefinitionException
     */
    public static function kindOf(string $field): string
    {
        return self::FIELDS[$field] ?? throw CrmSegmentDefinitionException::because('unknown_field');
    }

    /**
     * Operators legitimately offered for a field. The UI renders exactly this list, so
     * an operator that the authority would refuse is never even selectable.
     *
     * @return list<string>
     *
     * @throws CrmSegmentDefinitionException
     */
    public static function operatorsFor(string $field): array
    {
        return match (self::kindOf($field)) {
            'commerce_number' => self::NUMERIC_OPERATORS,
            'commerce_date', 'contact_date' => self::DATE_OPERATORS,
            'contact_enum' => self::ENUM_OPERATORS,
        };
    }

    /** Only commerce.* criteria are currency-scoped; contact.* must never carry one. */
    public static function requiresCurrency(string $field): bool
    {
        return str_starts_with(self::kindOf($field), 'commerce_');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     *
     * @throws CrmSegmentDefinitionException
     */
    private static function criterion(array $row): array
    {
        $field = self::string($row, 'field');
        $operator = self::string($row, 'operator');
        $kind = self::kindOf($field);

        if (! in_array($operator, self::operatorsFor($field), true)) {
            throw CrmSegmentDefinitionException::because('invalid_operator');
        }

        return match ($kind) {
            'commerce_number' => self::numericCriterion($row, $field, $operator),
            'commerce_date' => self::dateCriterion($row, $field, $operator, withCurrency: true),
            'contact_date' => self::dateCriterion($row, $field, $operator, withCurrency: false),
            'contact_enum' => self::enumCriterion($row, $field, $operator),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function numericCriterion(array $row, string $field, string $operator): array
    {
        $currency = self::currency($row);

        if ($operator === 'between') {
            $lower = self::integer($row, 'lower');
            $upper = self::integer($row, 'upper');

            if ($lower > $upper) {
                throw CrmSegmentDefinitionException::because('inverted_bounds');
            }

            return ['currency' => $currency, 'field' => $field, 'lower' => $lower, 'operator' => $operator, 'upper' => $upper];
        }

        return ['currency' => $currency, 'field' => $field, 'operator' => $operator, 'value' => self::integer($row, 'value')];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function dateCriterion(array $row, string $field, string $operator, bool $withCurrency): array
    {
        if ($operator === 'between') {
            $lower = self::timestamp($row, 'lower');
            $upper = self::timestamp($row, 'upper');

            if (strcmp($lower, $upper) > 0) {
                throw CrmSegmentDefinitionException::because('inverted_bounds');
            }

            $criterion = ['field' => $field, 'lower' => $lower, 'operator' => $operator, 'upper' => $upper];
        } else {
            $criterion = ['field' => $field, 'operator' => $operator, 'value' => self::timestamp($row, 'value')];
        }

        return $withCurrency ? ['currency' => self::currency($row)] + $criterion : $criterion;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function enumCriterion(array $row, string $field, string $operator): array
    {
        $allowed = self::ENUM_VALUES[$field] ?? throw CrmSegmentDefinitionException::because('unknown_field');
        $values = $row['values'] ?? null;

        if (! is_array($values) || $values === []) {
            throw CrmSegmentDefinitionException::because('values_required');
        }

        $clean = [];

        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                throw CrmSegmentDefinitionException::because('unknown_enum_value');
            }

            // The authority refuses duplicates outright; refuse them here too rather
            // than silently de-duplicating a choice the operator actually made.
            if (in_array($value, $clean, true)) {
                throw CrmSegmentDefinitionException::because('duplicate_enum_value');
            }

            $clean[] = $value;
        }

        return ['field' => $field, 'operator' => $operator, 'values' => $clean];
    }

    /** @param array<string, mixed> $row */
    private static function currency(array $row): string
    {
        $currency = $row['currency'] ?? null;

        // Same anchored pattern as the authority. `$` alone would accept "XOF\n" — the
        // exact defect P3-D1.1 A1 closed in Money.
        if (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw CrmSegmentDefinitionException::because('invalid_currency');
        }

        return $currency;
    }

    /**
     * Exact signed 64-bit integer. Emitted as a PHP int so `json_encode` produces a JSON
     * NUMBER — the authority rejects "100" as a string.
     *
     * @param  array<string, mixed>  $row
     */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        // Livewire may hand back a native int for a `number` input; a bool must never
        // be silently read as 0/1, so it is excluded explicitly.
        if (is_int($value) && ! is_bool($value)) {
            return $value;
        }

        // A missing input and a malformed one are the SAME operator mistake for a
        // numeric field, so they carry one kind-accurate reason instead of a generic
        // "missing" code the UI would have to disambiguate by guessing the field kind.
        if (! is_string($value)) {
            throw CrmSegmentDefinitionException::because('invalid_integer');
        }

        $raw = trim($value);

        // No float, no exponent, no thousands separator, no leading '+'.
        if (preg_match('/\A-?[0-9]+\z/', $raw) !== 1) {
            throw CrmSegmentDefinitionException::because('invalid_integer');
        }

        $int = (int) $raw;

        // Round-trip proof: a value beyond BIGINT saturates on cast, so the canonical
        // form stops matching and the overflow is refused instead of silently clamped.
        if (self::canonicalInteger($raw) !== (string) $int) {
            throw CrmSegmentDefinitionException::because('integer_out_of_range');
        }

        return $int;
    }

    /** Strip leading zeros and normalise "-0" so the round-trip compares like for like. */
    private static function canonicalInteger(string $raw): string
    {
        $negative = str_starts_with($raw, '-');
        $digits = ltrim($negative ? substr($raw, 1) : $raw, '0');

        if ($digits === '') {
            return '0';
        }

        return ($negative ? '-' : '').$digits;
    }

    /**
     * Absolute UTC RFC3339 with an explicit `Z`. Relative expressions ("30 days ago"),
     * naive datetimes and non-UTC offsets are all refused: a segment definition is
     * immutable, so a relative bound would silently change meaning over time.
     *
     * @param  array<string, mixed>  $row
     */
    private static function timestamp(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value)) {
            throw CrmSegmentDefinitionException::because('invalid_timestamp');
        }

        $raw = trim($value);

        // `datetime-local` yields "Y-m-dTH:i"; seconds and the UTC marker are appended
        // by US, never guessed from a locale or a server timezone.
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $raw) === 1) {
            $raw .= ':00';
        }

        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $raw) === 1) {
            $raw .= 'Z';
        }

        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})Z\z/', $raw, $m) !== 1) {
            throw CrmSegmentDefinitionException::because('invalid_timestamp');
        }

        [, $year, $month, $day, $hour, $minute, $second] = $m;

        if (! checkdate((int) $month, (int) $day, (int) $year)
            || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            throw CrmSegmentDefinitionException::because('invalid_timestamp');
        }

        return $raw;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw CrmSegmentDefinitionException::because('missing_'.$key);
        }

        return $value;
    }
}
