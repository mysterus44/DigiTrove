<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-B0.1 read authorities: the ONLY way the admin UI reaches CRM data. Read-only,
 * keyset-bounded, allowlisted filters, exact e-mail only, currency-separated commerce.
 */
function p6b01(string $sql, array $bindings = []): array
{
    return Fx::owner()->select($sql, $bindings);
}

// ── Contact list ────────────────────────────────────────────────────────────────

it('lists contacts by bounded keyset with allowlisted filters', function () {
    $a = Fx::contact('active', 'guest_order');
    $b = Fx::contact('active', 'verified_account');

    $all = p6b01('SELECT * FROM list_crm_contacts(NULL, NULL, NULL, 100)');
    expect(collect($all)->pluck('contact_id')->map(fn ($v) => (int) $v)->all())->toBe([$a, $b]);

    // Keyset: after $a returns only $b.
    $after = p6b01('SELECT * FROM list_crm_contacts(?, NULL, NULL, 100)', [$a]);
    expect(collect($after)->pluck('contact_id')->map(fn ($v) => (int) $v)->all())->toBe([$b]);

    // Allowlisted filters.
    $guests = p6b01('SELECT * FROM list_crm_contacts(NULL, NULL, ?, 100)', ['guest_order']);
    expect(collect($guests)->pluck('contact_id')->map(fn ($v) => (int) $v)->all())->toBe([$a]);
});

it('rejects an out-of-range page size or an unknown filter value', function () {
    expect(fn () => p6b01('SELECT * FROM list_crm_contacts(NULL, NULL, NULL, 0)'))->toThrow(QueryException::class);
    expect(fn () => p6b01('SELECT * FROM list_crm_contacts(NULL, NULL, NULL, 101)'))->toThrow(QueryException::class);
    expect(fn () => p6b01('SELECT * FROM list_crm_contacts(NULL, ?, NULL, 10)', ['deleted']))->toThrow(QueryException::class);
    expect(fn () => p6b01('SELECT * FROM list_crm_contacts(NULL, NULL, ?, 10)', ['import']))->toThrow(QueryException::class);
});

it('returns a null e-mail for an anonymized contact', function () {
    $anonymized = Fx::contact('anonymized', 'verified_account');

    $row = p6b01('SELECT * FROM get_crm_contact(?)', [$anonymized])[0];
    expect((int) $row->contact_id)->toBe($anonymized)
        ->and($row->email)->toBeNull()
        ->and($row->status)->toBe('anonymized')
        ->and($row->anonymized_at)->not->toBeNull();
});

// ── Exact e-mail search ─────────────────────────────────────────────────────────

it('finds a contact only by its exact normalised e-mail', function () {
    $contactId = Fx::contact();
    $email = (string) Fx::owner()->selectOne('SELECT email FROM crm_contacts WHERE id = ?', [$contactId])->email;

    expect(p6b01('SELECT * FROM find_crm_contact_by_exact_email(?)', [$email]))->toHaveCount(1);
    // Case and surrounding whitespace are normalised exactly like P6-A0 does.
    expect(p6b01('SELECT * FROM find_crm_contact_by_exact_email(?)', [strtoupper($email)]))->toHaveCount(1);
    expect(p6b01('SELECT * FROM find_crm_contact_by_exact_email(?)', ['  '.$email.'  ']))->toHaveCount(1);
});

it('never matches a partial, pattern or fuzzy e-mail', function () {
    $contactId = Fx::contact();
    $email = (string) Fx::owner()->selectOne('SELECT email FROM crm_contacts WHERE id = ?', [$contactId])->email;
    [$local, $domain] = explode('@', $email);

    foreach ([
        $local,                       // local part alone
        '@'.$domain,                  // domain alone
        substr($email, 0, -1),        // truncated
        '%'.$email,                   // LIKE-style wildcard
        $email.'%',
        '%@example.com',
        $local.'+tag@'.$domain,       // plus-addressing must NOT fold
    ] as $needle) {
        expect(p6b01('SELECT * FROM find_crm_contact_by_exact_email(?)', [$needle]))->toBe([]);
    }
});

it('can never retrieve an anonymized contact through any e-mail', function () {
    $anonymized = Fx::contact('anonymized', 'guest_order');

    // Its address is physically NULL (P6-A0 state CHECK), so nothing can resolve it.
    expect(Fx::owner()->selectOne('SELECT email FROM crm_contacts WHERE id = ?', [$anonymized])->email)->toBeNull()
        ->and(p6b01('SELECT * FROM find_crm_contact_by_exact_email(?)', ['anything@example.com']))->toBe([])
        ->and(p6b01("SELECT * FROM find_crm_contact_by_exact_email('')"))->toBe([]);
});

// ── Consent timeline ────────────────────────────────────────────────────────────

it('reads the consent ledger for one contact with keyset pagination', function () {
    $contactId = Fx::contact();
    $other = Fx::contact();
    Fx::grantMarketingConsent($contactId);
    Fx::grantMarketingConsent($contactId);
    Fx::grantMarketingConsent($other);

    $events = p6b01('SELECT * FROM list_crm_contact_consent_events(?, NULL, 100)', [$contactId]);
    expect($events)->toHaveCount(2);
    expect($events[0]->action)->toBe('granted')
        ->and($events[0]->channel)->toBe('email')
        ->and($events[0]->purpose)->toBe('promotional');

    // Keyset skips the first event; contacts never bleed into each other.
    $after = p6b01('SELECT * FROM list_crm_contact_consent_events(?, ?, 100)', [$contactId, (int) $events[0]->event_id]);
    expect($after)->toHaveCount(1)
        ->and(p6b01('SELECT * FROM list_crm_contact_consent_events(?, NULL, 100)', [$other]))->toHaveCount(1);
});

// ── Commerce facts ──────────────────────────────────────────────────────────────

it('returns one commerce row per currency and never a cross-currency total', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', acquiredOrders: 2, gross: 50000, refunded: 5000);
    Fx::rollup($contactId, 'USD', acquiredOrders: 1, gross: 3000, refunded: 0);

    $rows = p6b01('SELECT * FROM list_crm_contact_commerce_rollups(?)', [$contactId]);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->currency)->toBe('USD')
        ->and((int) $rows[0]->net_revenue_minor)->toBe(3000)
        ->and($rows[1]->currency)->toBe('XOF')
        ->and((int) $rows[1]->net_revenue_minor)->toBe(45000);

    // 45000 + 3000 is never produced by the authority: the caller gets separate rows.
    expect(collect($rows)->pluck('net_revenue_minor'))->not->toContain(48000);
});

it('returns no commerce row for a contact that never acquired', function () {
    expect(p6b01('SELECT * FROM list_crm_contact_commerce_rollups(?)', [Fx::contact()]))->toBe([]);
});

// ── Segment memberships of a contact ────────────────────────────────────────────

it('lists only memberships of the current published generation', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 50000);
    $definition = Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1000]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);

    // Before any build there is no current generation, hence no membership.
    expect(p6b01('SELECT * FROM list_crm_contact_segment_memberships(?, NULL, 100)', [$contactId]))->toBe([]);

    Fx::buildGeneration($segmentId);
    $memberships = p6b01('SELECT * FROM list_crm_contact_segment_memberships(?, NULL, 100)', [$contactId]);
    expect($memberships)->toHaveCount(1)
        ->and((int) $memberships[0]->segment_id)->toBe($segmentId)
        ->and($memberships[0]->generation_published_at)->not->toBeNull();

    // A second generation supersedes the first: still exactly one membership row.
    Fx::buildGeneration($segmentId);
    expect(p6b01('SELECT * FROM list_crm_contact_segment_memberships(?, NULL, 100)', [$contactId]))->toHaveCount(1);
});

it('excludes a contact that is not in the current generation', function () {
    $member = Fx::contact();
    Fx::rollup($member, 'XOF', gross: 50000);
    $outsider = Fx::contact();
    Fx::rollup($outsider, 'XOF', gross: 1);

    $definition = Fx::definition(['field' => 'commerce.net_revenue_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1000]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);
    Fx::buildGeneration($segmentId);

    expect(p6b01('SELECT * FROM list_crm_contact_segment_memberships(?, NULL, 100)', [$member]))->toHaveCount(1)
        ->and(p6b01('SELECT * FROM list_crm_contact_segment_memberships(?, NULL, 100)', [$outsider]))->toBe([]);
});

// ── Segment version history ─────────────────────────────────────────────────────

it('lists the version history of one segment in order', function () {
    $definition = Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']]);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion($definition);

    Fx::owner()->selectOne('SELECT * FROM create_crm_segment_version(?, ?::jsonb)', [
        $segmentId,
        json_encode(Fx::definition(['field' => 'contact.origin', 'operator' => 'in', 'values' => ['guest_order']]), JSON_THROW_ON_ERROR),
    ]);

    $versions = p6b01('SELECT * FROM list_crm_segment_versions(?, NULL, 100)', [$segmentId]);
    expect($versions)->toHaveCount(2)
        ->and((int) $versions[0]->version_number)->toBe(1)
        ->and($versions[0]->status)->toBe('published')
        ->and((int) $versions[1]->version_number)->toBe(2)
        ->and($versions[1]->status)->toBe('draft')
        ->and((int) $versions[0]->definition_schema_version)->toBe(1);

    $after = p6b01('SELECT * FROM list_crm_segment_versions(?, 1, 100)', [$segmentId]);
    expect($after)->toHaveCount(1)
        ->and((int) $after[0]->version_number)->toBe(2);
});

it('rejects an invalid identifier or page size on every authority', function () {
    expect(fn () => p6b01('SELECT * FROM get_crm_contact(0)'))->toThrow(QueryException::class);
    expect(fn () => p6b01('SELECT * FROM list_crm_contact_consent_events(0, NULL, 10)'))->toThrow(QueryException::class);
    expect(fn () => p6b01('SELECT * FROM list_crm_contact_consent_events(1, NULL, 101)'))->toThrow(QueryException::class);
    expect(fn () => p6b01('SELECT * FROM list_crm_contact_commerce_rollups(0)'))->toThrow(QueryException::class);
    expect(fn () => p6b01('SELECT * FROM list_crm_contact_segment_memberships(1, NULL, 0)'))->toThrow(QueryException::class);
    expect(fn () => p6b01('SELECT * FROM list_crm_segment_versions(0, NULL, 10)'))->toThrow(QueryException::class);
});

// ── Read-only guarantee ─────────────────────────────────────────────────────────

it('keeps every read authority STABLE so none of them can mutate', function () {
    foreach ([
        'list_crm_contacts',
        'get_crm_contact',
        'find_crm_contact_by_exact_email',
        'list_crm_contact_consent_events',
        'list_crm_contact_commerce_rollups',
        'list_crm_contact_segment_memberships',
        'list_crm_segment_versions',
    ] as $function) {
        // provolatile 's' = STABLE: PostgreSQL forbids any data modification inside.
        expect((string) DB::connection('pgsql_migration')->selectOne(
            'SELECT provolatile FROM pg_proc WHERE proname = ?', [$function],
        )->provolatile)->toBe('s');
    }
});
