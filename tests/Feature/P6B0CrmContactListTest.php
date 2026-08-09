<?php

declare(strict_types=1);

use App\Filament\Pages\CrmContacts;
use App\Services\Crm\CrmAdminReadService;
use App\Services\Crm\CrmOperationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

it('lists contacts through the authority and renders them', function () {
    $a = Fx::contact('active', 'guest_order');
    $b = Fx::contact('active', 'verified_account');

    test()->actingAs(Admin::admin());

    Livewire::test(CrmContacts::class)
        ->assertSee(Admin::emailOf($a))
        ->assertSee(Admin::emailOf($b))
        ->assertSet('unavailable', false);
});

it('applies only allowlisted status and origin filters', function () {
    $guest = Fx::contact('active', 'guest_order');
    $verified = Fx::contact('active', 'verified_account');

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmContacts::class)->set('originFilter', 'guest_order');
    expect(collect($component->instance()->contacts())->pluck('contact_id')->all())->toBe([$guest]);

    // An off-allowlist filter is neutralised to "no filter", never forwarded to the
    // authority as free text — so it can neither widen nor inject anything.
    $component->set('originFilter', "guest_order' OR '1'='1");
    expect(collect($component->instance()->contacts())->pluck('contact_id')->all())
        ->toBe([$guest, $verified]);

    $component->set('statusFilter', 'deleted');
    expect(collect($component->instance()->contacts())->pluck('contact_id')->all())
        ->toBe([$guest, $verified]);
});

it('pages by keyset and never by offset', function () {
    $ids = [];

    for ($i = 0; $i < 3; $i++) {
        $ids[] = Fx::contact();
    }

    test()->actingAs(Admin::admin());

    $component = Livewire::test(CrmContacts::class);
    $page = $component->instance();

    // A deliberately small page proves the cursor advances on the LAST id returned.
    $first = app(CrmAdminReadService::class)->listContacts(null, null, null, 2);
    expect(collect($first)->pluck('contact_id')->all())->toBe([$ids[0], $ids[1]]);

    $second = app(CrmAdminReadService::class)->listContacts($ids[1], null, null, 2);
    expect(collect($second)->pluck('contact_id')->all())->toBe([$ids[2]]);

    // The page's own cursor stack round-trips without an OFFSET anywhere.
    $component->call('nextPage');
    expect($component->get('afterContactId'))->toBe($ids[2]);
    $component->call('previousPage');
    expect($component->get('afterContactId'))->toBeNull();

    // The real guarantee lives in the AUTHORITY BODIES, not in PHP: a source grep would
    // trip over the word "OFFSET" in an explanatory comment and would miss an OFFSET
    // that PostgreSQL actually executes. pg_get_functiondef reads what runs.
    foreach (['list_crm_contacts', 'list_crm_contact_consent_events', 'list_crm_contact_segment_memberships'] as $function) {
        $body = (string) DB::connection('pgsql_migration')->selectOne(
            'SELECT pg_get_functiondef(oid) AS def FROM pg_proc WHERE proname = ?',
            [$function],
        )->def;

        expect($body)->not->toContain('OFFSET')
            ->and($body)->toContain('LIMIT');
    }
});

it('renders an anonymized contact without any address', function () {
    $anonymized = Fx::contact('anonymized', 'verified_account');

    test()->actingAs(Admin::admin());

    Livewire::test(CrmContacts::class)
        ->assertSee('Anonymisé')
        ->assertDontSee('@example.com');
});

it('refuses the page size contract outside the authority bounds', function () {
    test()->actingAs(Admin::admin());

    // The authority owns the bound; the service does not silently clamp it. The refusal
    // surfaces as the sanitized CRM exception, never as a driver message.
    expect(fn () => app(CrmAdminReadService::class)->listContacts(null, null, null, 0))
        ->toThrow(CrmOperationException::class)
        ->and(fn () => app(CrmAdminReadService::class)->listContacts(null, null, null, 101))
        ->toThrow(CrmOperationException::class);
});
