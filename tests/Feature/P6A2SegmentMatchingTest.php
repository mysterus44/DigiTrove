<?php

declare(strict_types=1);

use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A2 matcher: currency-scoped commerce criteria, contact enums/dates, match modes,
 * missing-rollup semantics and strict independence from marketing consent.
 */

// ── Numeric operators ───────────────────────────────────────────────────────────

it('evaluates every numeric operator against the currency-scoped rollup', function (string $operator, array $extra, bool $expected) {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', acquiredOrders: 3, gross: 10000, refunded: 2000);

    $criterion = array_merge([
        'field' => 'commerce.net_revenue_minor',
        'operator' => $operator,
        'currency' => 'XOF',
    ], $extra);

    expect(Fx::matches($contactId, Fx::definition($criterion)))->toBe($expected);
})->with([
    // net = 10000 - 2000 = 8000
    'eq true' => ['eq', ['value' => 8000], true],
    'eq false' => ['eq', ['value' => 7999], false],
    'neq true' => ['neq', ['value' => 1], true],
    'neq false' => ['neq', ['value' => 8000], false],
    'gt boundary' => ['gt', ['value' => 8000], false],
    'gt true' => ['gt', ['value' => 7999], true],
    'gte boundary' => ['gte', ['value' => 8000], true],
    'lt boundary' => ['lt', ['value' => 8000], false],
    'lte boundary' => ['lte', ['value' => 8000], true],
    'between inclusive lower' => ['between', ['lower' => 8000, 'upper' => 9000], true],
    'between inclusive upper' => ['between', ['lower' => 7000, 'upper' => 8000], true],
    'between outside' => ['between', ['lower' => 1, 'upper' => 7999], false],
]);

it('reads the other numeric commerce fields from the same rollup row', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', acquiredOrders: 4, gross: 10000, refunded: 2500);

    expect(Fx::matches($contactId, Fx::definition(['field' => 'commerce.gross_revenue_minor', 'operator' => 'eq', 'currency' => 'XOF', 'value' => 10000])))->toBeTrue()
        ->and(Fx::matches($contactId, Fx::definition(['field' => 'commerce.refunded_amount_minor', 'operator' => 'eq', 'currency' => 'XOF', 'value' => 2500])))->toBeTrue()
        ->and(Fx::matches($contactId, Fx::definition(['field' => 'commerce.acquired_orders_count', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 4])))->toBeTrue();
});

// ── Currency safety ─────────────────────────────────────────────────────────────

it('never lets one currency read another currency row', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', acquiredOrders: 1, gross: 100000, refunded: 0);
    Fx::rollup($contactId, 'USD', acquiredOrders: 1, gross: 5, refunded: 0);

    // XOF is big, USD is tiny: each criterion sees only its own row.
    expect(Fx::matches($contactId, Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 100000])))->toBeTrue()
        ->and(Fx::matches($contactId, Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'USD', 'value' => 100000])))->toBeFalse()
        ->and(Fx::matches($contactId, Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'eq', 'currency' => 'USD', 'value' => 5])))->toBeTrue();
});

it('never sums currencies: a multi-currency all/any is explicit per currency', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', acquiredOrders: 1, gross: 6000, refunded: 0);
    Fx::rollup($contactId, 'USD', acquiredOrders: 1, gross: 6000, refunded: 0);

    $both = [
        'schema_version' => 1,
        'match' => 'all',
        'criteria' => [
            ['field' => 'commerce.net_revenue_minor', 'operator' => 'eq', 'currency' => 'XOF', 'value' => 6000],
            ['field' => 'commerce.net_revenue_minor', 'operator' => 'eq', 'currency' => 'USD', 'value' => 6000],
        ],
    ];
    expect(Fx::matches($contactId, $both))->toBeTrue();

    // 6000 + 6000 is NEVER computed: no criterion can ever see 12000.
    $summed = Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'eq', 'currency' => 'XOF', 'value' => 12000]);
    expect(Fx::matches($contactId, $summed))->toBeFalse();
});

// ── Missing rollup ──────────────────────────────────────────────────────────────

it('evaluates FALSE for every operator when the rollup row is missing', function (string $operator, array $extra) {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'USD', acquiredOrders: 1, gross: 9999, refunded: 0);

    $criterion = array_merge([
        'field' => 'commerce.net_revenue_minor',
        'operator' => $operator,
        'currency' => 'XOF',   // no XOF row at all
    ], $extra);

    expect(Fx::matches($contactId, Fx::definition($criterion)))->toBeFalse();
})->with([
    'eq' => ['eq', ['value' => 0]],
    // neq must NOT become true just because the row is absent.
    'neq' => ['neq', ['value' => 12345]],
    'gte zero' => ['gte', ['value' => 0]],
    'lte zero' => ['lte', ['value' => 0]],
    'between' => ['between', ['lower' => 0, 'upper' => 100000]],
]);

it('distinguishes a free acquirer from someone who never acquired', function () {
    $free = Fx::contact();
    Fx::rollup($free, 'XOF', acquiredOrders: 1, gross: 0, refunded: 0);
    $never = Fx::contact();   // no rollup row at all

    $zeroNet = Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'eq', 'currency' => 'XOF', 'value' => 0]);

    expect(Fx::matches($free, $zeroNet))->toBeTrue()
        ->and(Fx::matches($never, $zeroNet))->toBeFalse();

    $acquired = Fx::definition(['field' => 'commerce.acquired_orders_count', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1]);
    expect(Fx::matches($free, $acquired))->toBeTrue()
        ->and(Fx::matches($never, $acquired))->toBeFalse();
});

// ── Dates ───────────────────────────────────────────────────────────────────────

it('evaluates commerce date operators on absolute UTC bounds', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', acquiredOrders: 1, gross: 100, refunded: 0,
        firstAcquiredAt: '2026-03-01T12:00:00Z', lastAcquiredAt: '2026-06-01T12:00:00Z');

    expect(Fx::matches($contactId, Fx::definition(['field' => 'commerce.first_acquired_at', 'operator' => 'after', 'currency' => 'XOF', 'value' => '2026-01-01T00:00:00Z'])))->toBeTrue()
        ->and(Fx::matches($contactId, Fx::definition(['field' => 'commerce.first_acquired_at', 'operator' => 'before', 'currency' => 'XOF', 'value' => '2026-01-01T00:00:00Z'])))->toBeFalse()
        ->and(Fx::matches($contactId, Fx::definition(['field' => 'commerce.last_acquired_at', 'operator' => 'between', 'currency' => 'XOF', 'lower' => '2026-05-01T00:00:00Z', 'upper' => '2026-07-01T00:00:00Z'])))->toBeTrue();
});

it('evaluates contact.created_at and treats a null timestamp as false', function () {
    $contactId = Fx::contact();
    expect(Fx::matches($contactId, Fx::definition(['field' => 'contact.created_at', 'operator' => 'after', 'value' => '2000-01-01T00:00:00Z'])))->toBeTrue();

    // crm_contacts.created_at is nullable: an explicit FALSE, never a leaking NULL.
    // The row is inserted that way — P6-A0 forbids mutating an existing contact.
    $nullCreatedAt = Fx::contactWithoutCreatedAt();
    expect(Fx::matches($nullCreatedAt, Fx::definition(['field' => 'contact.created_at', 'operator' => 'after', 'value' => '2000-01-01T00:00:00Z'])))->toBeFalse()
        ->and(Fx::matches($nullCreatedAt, Fx::definition(['field' => 'contact.created_at', 'operator' => 'before', 'value' => '2100-01-01T00:00:00Z'])))->toBeFalse()
        ->and(Fx::matches($nullCreatedAt, Fx::definition(['field' => 'contact.created_at', 'operator' => 'between', 'lower' => '2000-01-01T00:00:00Z', 'upper' => '2100-01-01T00:00:00Z'])))->toBeFalse();
});

// ── Enums (real repository values) ──────────────────────────────────────────────

it('evaluates contact status and origin against the real allowed values', function () {
    $activeGuest = Fx::contact('active', 'guest_order');
    $anonymized = Fx::contact('anonymized', 'verified_account');

    expect(Fx::matches($activeGuest, Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']])))->toBeTrue()
        ->and(Fx::matches($anonymized, Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']])))->toBeFalse()
        ->and(Fx::matches($anonymized, Fx::definition(['field' => 'contact.status', 'operator' => 'not_in', 'values' => ['active']])))->toBeTrue()
        ->and(Fx::matches($activeGuest, Fx::definition(['field' => 'contact.origin', 'operator' => 'in', 'values' => ['guest_order']])))->toBeTrue()
        ->and(Fx::matches($activeGuest, Fx::definition(['field' => 'contact.origin', 'operator' => 'not_in', 'values' => ['guest_order']])))->toBeFalse();
});

// ── Match modes ─────────────────────────────────────────────────────────────────

it('requires every criterion for match=all and one for match=any', function () {
    $contactId = Fx::contact('active', 'guest_order');
    Fx::rollup($contactId, 'XOF', acquiredOrders: 1, gross: 5000, refunded: 0);

    $trueAndFalse = [
        ['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']],                                   // true
        ['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 999999],      // false
    ];

    expect(Fx::matches($contactId, ['schema_version' => 1, 'match' => 'all', 'criteria' => $trueAndFalse]))->toBeFalse()
        ->and(Fx::matches($contactId, ['schema_version' => 1, 'match' => 'any', 'criteria' => $trueAndFalse]))->toBeTrue();
});

// ── Consent separation ──────────────────────────────────────────────────────────

it('gives identical membership to two contacts differing only by marketing consent', function () {
    $withConsent = Fx::contact('active', 'verified_account');
    $withoutConsent = Fx::contact('active', 'verified_account');
    Fx::rollup($withConsent, 'XOF', acquiredOrders: 2, gross: 7000, refunded: 0);
    Fx::rollup($withoutConsent, 'XOF', acquiredOrders: 2, gross: 7000, refunded: 0);

    // Only ONE of them has a marketing consent grant.
    Fx::grantMarketingConsent($withConsent);

    $definition = Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 7000]);

    // Membership is a fact about CRM/commerce, never about send eligibility.
    expect(Fx::matches($withConsent, $definition))->toBeTrue()
        ->and(Fx::matches($withoutConsent, $definition))->toBeTrue();
});

it('returns false for a contact that does not exist', function () {
    expect(Fx::matches(999999, Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']])))->toBeFalse();
});
