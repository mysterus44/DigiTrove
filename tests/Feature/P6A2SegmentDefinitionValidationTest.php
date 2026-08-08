<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A2 definition DSL v1: strict, fail-closed, allowlisted validation. Anything that
 * is not explicitly permitted is rejected — it is never "ignored" and never executed.
 */
function p6a2Validate(array $definition): void
{
    Fx::owner()->select('SELECT validate_crm_segment_definition_v1(?::jsonb)', [json_encode($definition, JSON_THROW_ON_ERROR)]);
}

function p6a2Accepts(array $definition): void
{
    p6a2Validate($definition);
    expect(true)->toBeTrue();
}

function p6a2Rejects(array $definition): void
{
    expect(fn () => p6a2Validate($definition))->toThrow(QueryException::class);
}

function p6a2Numeric(array $overrides = []): array
{
    $criterion = array_merge([
        'field' => 'commerce.net_revenue_minor',
        'operator' => 'gte',
        'currency' => 'XOF',
        'value' => 1000,
    ], $overrides);

    // `between` carries lower/upper INSTEAD of value: keeping both would itself be an
    // unexpected-key violation.
    if (($criterion['operator'] ?? null) === 'between' && ! array_key_exists('value', $overrides)) {
        unset($criterion['value']);
    }

    return $criterion;
}

// ── Envelope ────────────────────────────────────────────────────────────────────

it('accepts a minimal valid definition', function () {
    p6a2Accepts(Fx::definition(p6a2Numeric()));
});

it('rejects an unsupported schema version', function (mixed $version) {
    p6a2Rejects(['schema_version' => $version, 'match' => 'all', 'criteria' => [p6a2Numeric()]]);
})->with([2, 0, -1, '1']);

it('accepts both match modes and rejects any other', function () {
    p6a2Accepts(Fx::definition(p6a2Numeric(), 'all'));
    p6a2Accepts(Fx::definition(p6a2Numeric(), 'any'));

    foreach (['none', 'ALL', 'and', 'or', ''] as $mode) {
        p6a2Rejects(['schema_version' => 1, 'match' => $mode, 'criteria' => [p6a2Numeric()]]);
    }
});

it('bounds the criteria count between 1 and 50', function () {
    p6a2Rejects(['schema_version' => 1, 'match' => 'all', 'criteria' => []]);
    p6a2Accepts(['schema_version' => 1, 'match' => 'all', 'criteria' => array_fill(0, 1, p6a2Numeric())]);
    p6a2Accepts(['schema_version' => 1, 'match' => 'all', 'criteria' => array_fill(0, 50, p6a2Numeric())]);
    p6a2Rejects(['schema_version' => 1, 'match' => 'all', 'criteria' => array_fill(0, 51, p6a2Numeric())]);
});

it('rejects any extra or missing top-level key', function () {
    p6a2Rejects(['schema_version' => 1, 'match' => 'all', 'criteria' => [p6a2Numeric()], 'sql' => 'SELECT 1']);
    p6a2Rejects(['schema_version' => 1, 'match' => 'all']);
    p6a2Rejects(['match' => 'all', 'criteria' => [p6a2Numeric()]]);
    p6a2Rejects(['schema_version' => 1, 'criteria' => [p6a2Numeric()]]);
});

// ── Fields and operators ────────────────────────────────────────────────────────

it('rejects an unknown field', function (string $field) {
    p6a2Rejects(Fx::definition(p6a2Numeric(['field' => $field])));
})->with([
    'orders.total_minor',
    'commerce.last_refunded_at',
    'commerce.global_ltv',
    'contact.email',
    'contact.user_id',
    'analytics.sessions',
    'net_revenue_minor',
    '',
]);

it('rejects an unknown operator for each field kind', function () {
    p6a2Rejects(Fx::definition(p6a2Numeric(['operator' => 'like'])));
    p6a2Rejects(Fx::definition(p6a2Numeric(['operator' => 'in'])));
    p6a2Rejects(Fx::definition(['field' => 'contact.status', 'operator' => 'eq', 'values' => ['active']]));
    p6a2Rejects(Fx::definition(['field' => 'contact.created_at', 'operator' => 'gte', 'value' => '2026-01-01T00:00:00Z']));
});

it('rejects any unexpected key inside a criterion', function (string $key) {
    p6a2Rejects(Fx::definition(p6a2Numeric([$key => 'x'])));
})->with(['sql', 'column', 'table', 'path', 'expression', 'callback', 'raw', 'where', 'having', 'join', 'order', 'select']);

it('rejects a criterion missing a required key', function () {
    p6a2Rejects(Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF']));
    p6a2Rejects(Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'between', 'currency' => 'XOF', 'lower' => 1]));
    p6a2Rejects(Fx::definition(['field' => 'contact.status', 'operator' => 'in']));
});

// ── Currency ────────────────────────────────────────────────────────────────────

it('requires a well-formed currency on every commerce criterion', function () {
    p6a2Rejects(Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'value' => 1000]));

    foreach (['xof', 'XO', 'XOFF', 'X0F', '', 'XOF '] as $currency) {
        p6a2Rejects(Fx::definition(p6a2Numeric(['currency' => $currency])));
    }
});

it('forbids a currency on contact criteria', function () {
    p6a2Rejects(Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active'], 'currency' => 'XOF']));
    p6a2Rejects(Fx::definition(['field' => 'contact.created_at', 'operator' => 'after', 'value' => '2026-01-01T00:00:00Z', 'currency' => 'XOF']));
});

// ── Numeric values ──────────────────────────────────────────────────────────────

it('accepts only exact BIGINT integers', function () {
    p6a2Accepts(Fx::definition(p6a2Numeric(['value' => 0])));
    p6a2Accepts(Fx::definition(p6a2Numeric(['value' => -5])));
    p6a2Accepts(Fx::definition(p6a2Numeric(['value' => 9223372036854775807])));
});

it('rejects fractional, stringified, boolean and out-of-range numbers', function () {
    p6a2Rejects(Fx::definition(p6a2Numeric(['value' => 1.2])));
    p6a2Rejects(Fx::definition(p6a2Numeric(['value' => '1000'])));
    p6a2Rejects(Fx::definition(p6a2Numeric(['value' => true])));
    p6a2Rejects(Fx::definition(p6a2Numeric(['value' => null])));

    // 1e100 and 9223372036854775808 both exceed BIGINT.
    $raw = '{"schema_version":1,"match":"all","criteria":[{"field":"commerce.net_revenue_minor","operator":"gte","currency":"XOF","value":1e100}]}';
    expect(fn () => Fx::owner()->select('SELECT validate_crm_segment_definition_v1(?::jsonb)', [$raw]))
        ->toThrow(QueryException::class);

    $overflow = '{"schema_version":1,"match":"all","criteria":[{"field":"commerce.net_revenue_minor","operator":"gte","currency":"XOF","value":9223372036854775808}]}';
    expect(fn () => Fx::owner()->select('SELECT validate_crm_segment_definition_v1(?::jsonb)', [$overflow]))
        ->toThrow(QueryException::class);
});

it('rejects inverted numeric between bounds', function () {
    p6a2Rejects(Fx::definition(p6a2Numeric(['operator' => 'between', 'lower' => 500, 'upper' => 100])));
    p6a2Accepts(Fx::definition(p6a2Numeric(['operator' => 'between', 'lower' => 100, 'upper' => 500])));
    // Equal bounds are a valid single-point range.
    p6a2Accepts(Fx::definition(p6a2Numeric(['operator' => 'between', 'lower' => 100, 'upper' => 100])));
});

// ── Timestamps ──────────────────────────────────────────────────────────────────

it('accepts only absolute UTC RFC3339 timestamps', function () {
    p6a2Accepts(Fx::definition(['field' => 'contact.created_at', 'operator' => 'after', 'value' => '2026-01-01T00:00:00Z']));
    p6a2Accepts(Fx::definition(['field' => 'contact.created_at', 'operator' => 'before', 'value' => '2026-01-01T00:00:00.123456Z']));
});

it('rejects relative, naive and offset timestamps', function (mixed $value) {
    p6a2Rejects(Fx::definition(['field' => 'contact.created_at', 'operator' => 'after', 'value' => $value]));
})->with([
    '30 days ago',
    'today',
    'now()',
    '2026-01-01',
    '2026-01-01T00:00:00',
    '2026-01-01T00:00:00+01:00',
    '2026-01-01 00:00:00Z',
    1735689600,
]);

it('rejects inverted timestamp between bounds', function () {
    p6a2Rejects(Fx::definition(['field' => 'contact.created_at', 'operator' => 'between', 'lower' => '2026-06-01T00:00:00Z', 'upper' => '2026-01-01T00:00:00Z']));
    p6a2Accepts(Fx::definition(['field' => 'contact.created_at', 'operator' => 'between', 'lower' => '2026-01-01T00:00:00Z', 'upper' => '2026-06-01T00:00:00Z']));
});

// ── Enums ───────────────────────────────────────────────────────────────────────

it('accepts only the real repository enum values', function () {
    p6a2Accepts(Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active', 'anonymized']]));
    p6a2Accepts(Fx::definition(['field' => 'contact.origin', 'operator' => 'not_in', 'values' => ['guest_order', 'verified_account']]));
});

it('rejects an unknown, empty, duplicated or non-string enum value list', function () {
    p6a2Rejects(Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['archived']]));
    p6a2Rejects(Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['deleted']]));
    p6a2Rejects(Fx::definition(['field' => 'contact.origin', 'operator' => 'in', 'values' => ['active']]));
    p6a2Rejects(Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => []]));
    p6a2Rejects(Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active', 'active']]));
    p6a2Rejects(Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => [1]]));
});

// ── Injection attempts are simply invalid ───────────────────────────────────────

it('treats every injection-shaped payload as an invalid definition, never as SQL', function (mixed $payload) {
    p6a2Rejects(Fx::definition(['field' => $payload, 'operator' => 'in', 'values' => ['active']]));
})->with([
    "contact.status'; DROP TABLE crm_segments; --",
    'orders.total_minor',
    'pg_sleep(10)',
    '(SELECT 1)',
    'contact.status OR 1=1',
]);

it('leaves the schema intact after every rejected definition', function () {
    // A rejected definition never reaches the database as SQL.
    p6a2Rejects(Fx::definition(['field' => "x'; DROP TABLE crm_segments; --", 'operator' => 'in', 'values' => ['active']]));

    expect(Fx::owner()->selectOne("SELECT to_regclass('public.crm_segments') AS present")->present)->not->toBeNull();
});
