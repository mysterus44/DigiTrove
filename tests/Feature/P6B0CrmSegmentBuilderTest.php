<?php

declare(strict_types=1);

use App\Services\Crm\CrmOperationException;
use App\Services\Crm\CrmSegmentService;
use App\Support\CrmSegmentDefinitionBuilder as Builder;
use App\Support\CrmSegmentDefinitionException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

// ── Numeric criteria ────────────────────────────────────────────────────────────

it('builds a currency-scoped numeric criterion with the exact allowlisted key set', function () {
    $definition = Builder::build('all', [[
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte',
        'currency' => 'XOF', 'value' => '1000',
    ]]);

    expect($definition)->toBe([
        'schema_version' => 1,
        'match' => 'all',
        'criteria' => [[
            'currency' => 'XOF',
            'field' => 'commerce.net_revenue_minor',
            'operator' => 'gte',
            // A JSON NUMBER, not the string "1000": the authority refuses the string.
            'value' => 1000,
        ]],
    ]);

    expect(json_encode($definition['criteria'][0]))->toContain('"value":1000')
        ->and(json_encode($definition['criteria'][0]))->not->toContain('"1000"');
});

it('builds a numeric between criterion with lower and upper', function () {
    $definition = Builder::build('any', [[
        'field' => 'commerce.acquired_orders_count', 'operator' => 'between',
        'currency' => 'USD', 'lower' => '1', 'upper' => '5',
    ]]);

    expect($definition['criteria'][0])->toBe([
        'currency' => 'USD', 'field' => 'commerce.acquired_orders_count',
        'lower' => 1, 'operator' => 'between', 'upper' => 5,
    ]);
});

it('refuses a non-integer, scientific, overflowing or inverted numeric value', function () {
    $base = ['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF'];

    foreach (['1.2', '1e100', ' 1 000', '1,000', 'abc', '+5', '0x10', ''] as $bad) {
        expect(fn () => Builder::build('all', [$base + ['value' => $bad]]))
            ->toThrow(CrmSegmentDefinitionException::class);
    }

    // Beyond signed BIGINT: refused, never silently clamped to PHP_INT_MAX.
    expect(fn () => Builder::build('all', [$base + ['value' => '9223372036854775808']]))
        ->toThrow(CrmSegmentDefinitionException::class);

    // Exactly at the bound is legitimate.
    expect(Builder::build('all', [$base + ['value' => '9223372036854775807']])['criteria'][0]['value'])
        ->toBe(9223372036854775807);

    // Leading zeros are canonicalised, not rejected outright.
    expect(Builder::build('all', [$base + ['value' => '007']])['criteria'][0]['value'])->toBe(7);

    expect(fn () => Builder::build('all', [[
        'field' => 'commerce.net_revenue_minor', 'operator' => 'between',
        'currency' => 'XOF', 'lower' => '10', 'upper' => '1',
    ]]))->toThrow(CrmSegmentDefinitionException::class);
});

// ── Date criteria ───────────────────────────────────────────────────────────────

it('emits absolute UTC RFC3339 timestamps and refuses relative expressions', function () {
    $definition = Builder::build('all', [[
        'field' => 'contact.created_at', 'operator' => 'after', 'value' => '2026-07-21T12:00',
    ]]);

    // A `datetime-local` value gains seconds and an explicit Z — never a server offset.
    expect($definition['criteria'][0])->toBe([
        'field' => 'contact.created_at', 'operator' => 'after', 'value' => '2026-07-21T12:00:00Z',
    ]);

    foreach (['30 days ago', 'now', 'yesterday', '2026-07-21', '2026-07-21T12:00:00+01:00', '2026-13-01T00:00:00Z', '2026-02-30T00:00:00Z'] as $bad) {
        expect(fn () => Builder::build('all', [[
            'field' => 'contact.created_at', 'operator' => 'after', 'value' => $bad,
        ]]))->toThrow(CrmSegmentDefinitionException::class);
    }
});

it('scopes a commerce date by currency and refuses inverted bounds', function () {
    $definition = Builder::build('all', [[
        'field' => 'commerce.last_acquired_at', 'operator' => 'between',
        'currency' => 'XOF', 'lower' => '2026-01-01T00:00:00', 'upper' => '2026-12-31T23:59:59',
    ]]);

    expect($definition['criteria'][0])->toBe([
        'currency' => 'XOF', 'field' => 'commerce.last_acquired_at',
        'lower' => '2026-01-01T00:00:00Z', 'operator' => 'between', 'upper' => '2026-12-31T23:59:59Z',
    ]);

    expect(fn () => Builder::build('all', [[
        'field' => 'commerce.last_acquired_at', 'operator' => 'between',
        'currency' => 'XOF', 'lower' => '2026-12-31T00:00:00', 'upper' => '2026-01-01T00:00:00',
    ]]))->toThrow(CrmSegmentDefinitionException::class);
});

// ── Enum criteria ───────────────────────────────────────────────────────────────

it('builds an enum criterion from real repository values only', function () {
    $definition = Builder::build('all', [[
        'field' => 'contact.status', 'operator' => 'in', 'values' => ['active', 'anonymized'],
    ]]);

    expect($definition['criteria'][0])->toBe([
        'field' => 'contact.status', 'operator' => 'in', 'values' => ['active', 'anonymized'],
    ]);

    foreach ([['deleted'], ['ACTIVE'], ['active', 'active'], [], ['active', 42]] as $bad) {
        expect(fn () => Builder::build('all', [[
            'field' => 'contact.status', 'operator' => 'in', 'values' => $bad,
        ]]))->toThrow(CrmSegmentDefinitionException::class);
    }

    expect(Builder::ENUM_VALUES['contact.origin'])->toBe(['guest_order', 'verified_account']);
});

// ── Currency scoping ────────────────────────────────────────────────────────────

it('requires a currency on commerce fields and forbids one on contact fields', function () {
    foreach (['commerce.net_revenue_minor', 'commerce.first_acquired_at'] as $field) {
        expect(Builder::requiresCurrency($field))->toBeTrue();
    }

    foreach (['contact.created_at', 'contact.status', 'contact.origin'] as $field) {
        expect(Builder::requiresCurrency($field))->toBeFalse();
    }

    // Missing currency on a commerce criterion is fatal.
    expect(fn () => Builder::build('all', [[
        'field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'value' => '1',
    ]]))->toThrow(CrmSegmentDefinitionException::class);

    // A currency supplied for a contact criterion is simply never emitted, so the exact
    // key set the authority demands is preserved.
    $definition = Builder::build('all', [[
        'field' => 'contact.status', 'operator' => 'in', 'values' => ['active'], 'currency' => 'XOF',
    ]]);
    expect($definition['criteria'][0])->not->toHaveKey('currency');

    foreach (['xof', 'XO', 'XOFF', "XOF\n", '', 'X0F'] as $bad) {
        expect(fn () => Builder::build('all', [[
            'field' => 'commerce.net_revenue_minor', 'operator' => 'gte',
            'currency' => $bad, 'value' => '1',
        ]]))->toThrow(CrmSegmentDefinitionException::class);
    }
});

// ── Closed allowlists ───────────────────────────────────────────────────────────

it('makes an unknown field or operator impossible to express', function () {
    expect(fn () => Builder::kindOf('contact.email'))->toThrow(CrmSegmentDefinitionException::class)
        ->and(fn () => Builder::kindOf('crm_contacts.email'))->toThrow(CrmSegmentDefinitionException::class)
        ->and(fn () => Builder::build('all', [[
            'field' => 'contact.email', 'operator' => 'in', 'values' => ['x'],
        ]]))->toThrow(CrmSegmentDefinitionException::class);

    // An operator valid for another KIND is still refused for this one.
    expect(fn () => Builder::build('all', [[
        'field' => 'contact.status', 'operator' => 'gte', 'value' => '1',
    ]]))->toThrow(CrmSegmentDefinitionException::class)
        ->and(fn () => Builder::build('all', [[
            'field' => 'commerce.net_revenue_minor', 'operator' => 'in',
            'currency' => 'XOF', 'values' => ['1'],
        ]]))->toThrow(CrmSegmentDefinitionException::class);

    expect(array_keys(Builder::FIELDS))->toHaveCount(9);
});

it('refuses an invalid match mode and an out-of-range criteria count', function () {
    $criterion = ['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']];

    expect(fn () => Builder::build('none', [$criterion]))->toThrow(CrmSegmentDefinitionException::class)
        ->and(fn () => Builder::build('ALL', [$criterion]))->toThrow(CrmSegmentDefinitionException::class)
        ->and(fn () => Builder::build('all', []))->toThrow(CrmSegmentDefinitionException::class)
        ->and(fn () => Builder::build('all', array_fill(0, 51, $criterion)))
        ->toThrow(CrmSegmentDefinitionException::class);

    expect(Builder::build('all', array_fill(0, 50, $criterion))['criteria'])->toHaveCount(50);
});

/**
 * The builder can only ever produce the three allowlisted top-level keys. A key like
 * `sql`, `column`, `raw` or `path` — the shapes an injection attempt would need — is
 * dropped on the floor, and the authority would refuse the definition anyway.
 */
it('never propagates an injected key from the form row into the definition', function () {
    $definition = Builder::build('all', [[
        'field' => 'contact.status',
        'operator' => 'in',
        'values' => ['active'],
        'sql' => 'DROP TABLE crm_contacts',
        'column' => 'email',
        'raw' => '1=1',
        'path' => '../../etc/passwd',
    ]]);

    expect(array_keys($definition))->toBe(['schema_version', 'match', 'criteria'])
        ->and(array_keys($definition['criteria'][0]))->toBe(['field', 'operator', 'values'])
        ->and(json_encode($definition))->not->toContain('DROP TABLE')
        ->and(json_encode($definition))->not->toContain('1=1');
});

// ── PostgreSQL remains the authority ────────────────────────────────────────────

/**
 * Mission-critical: PHP is NOT the final validator. Even bypassing the builder entirely
 * and handing a hand-crafted definition straight to the service, PostgreSQL refuses it
 * and no version row is created.
 */
it('lets PostgreSQL refuse a definition the builder never produced', function () {
    $segmentId = (int) Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', ['Direct'])->segment_id;
    $service = app(CrmSegmentService::class);

    $invalid = [
        'unknown field' => ['schema_version' => 1, 'match' => 'all', 'criteria' => [['field' => 'contact.email', 'operator' => 'in', 'values' => ['a']]]],
        'extra sql key' => ['schema_version' => 1, 'match' => 'all', 'criteria' => [['field' => 'contact.status', 'operator' => 'in', 'values' => ['active'], 'sql' => '1=1']]],
        'string number' => ['schema_version' => 1, 'match' => 'all', 'criteria' => [['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => '100']]],
        'relative date' => ['schema_version' => 1, 'match' => 'all', 'criteria' => [['field' => 'contact.created_at', 'operator' => 'after', 'value' => '30 days ago']]],
        'bad schema version' => ['schema_version' => 2, 'match' => 'all', 'criteria' => [['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']]]],
        'missing currency' => ['schema_version' => 1, 'match' => 'all', 'criteria' => [['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'value' => 100]]],
        'empty criteria' => ['schema_version' => 1, 'match' => 'all', 'criteria' => []],
    ];

    foreach ($invalid as $label => $definition) {
        expect(fn () => $service->createVersion($segmentId, $definition))
            ->toThrow(CrmOperationException::class, message: "PostgreSQL must refuse: {$label}");
    }

    // Not a single version row survived any of those attempts.
    expect((int) Fx::owner()->selectOne(
        'SELECT count(*) AS c FROM crm_segment_versions WHERE segment_id = ?', [$segmentId],
    )->c)->toBe(0);
});

it('accepts a builder-produced definition end to end', function () {
    $segmentId = (int) Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', ['Accepted'])->segment_id;

    $definition = Builder::build('all', [
        ['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => '1000'],
        ['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']],
    ]);

    $version = app(CrmSegmentService::class)->createVersion($segmentId, $definition);

    expect($version['version_number'])->toBe(1)
        ->and($version['status'])->toBe('draft');
});
