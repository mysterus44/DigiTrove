<?php

declare(strict_types=1);

use App\Filament\Pages\CrmContacts;
use App\Services\Crm\CrmAdminReadService;
use App\Services\Crm\CrmOperationException;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

it('finds a contact only by its exact normalised e-mail', function () {
    $contactId = Fx::contact();
    $email = Admin::emailOf($contactId);

    test()->actingAs(Admin::admin());

    Livewire::test(CrmContacts::class)
        ->set('email', $email)
        ->call('search')
        ->assertSet('selectedContactId', $contactId)
        ->assertSet('searchMissed', false);
});

/**
 * P6-A0 normalises with `lower(btrim(...))` over a CITEXT column. The UI must inherit
 * exactly that behaviour — not a PHP re-implementation that could drift from it.
 */
it('applies the P6-A0 normalisation for case and surrounding whitespace', function () {
    $contactId = Fx::contact();
    $email = Admin::emailOf($contactId);

    test()->actingAs(Admin::admin());

    foreach ([strtoupper($email), '  '.$email.'  ', ucfirst($email)] as $needle) {
        Livewire::test(CrmContacts::class)
            ->set('email', $needle)
            ->call('search')
            ->assertSet('selectedContactId', $contactId);
    }
});

/**
 * The heart of the contract: NOTHING but an exact address resolves a contact. A partial
 * match, a SQL wildcard or a bare domain would each turn the admin screen into an
 * enumeration oracle over customer e-mail addresses.
 */
it('never matches a partial, wildcard, domain-only or plus-addressed needle', function () {
    $contactId = Fx::contact();
    $email = Admin::emailOf($contactId);
    [$local, $domain] = explode('@', $email);

    test()->actingAs(Admin::admin());

    $needles = [
        'partial local part' => $local,
        'domain only' => $domain,
        'at-domain only' => '@'.$domain,
        'truncated' => substr($email, 0, -1),
        'leading percent wildcard' => '%'.$email,
        'trailing percent wildcard' => $email.'%',
        'bare percent' => '%',
        'percent domain' => '%@'.$domain,
        'underscore wildcard' => str_replace($local[0], '_', $email),
        'all underscores' => str_repeat('_', strlen($email)),
        'plus addressed' => $local.'+tag@'.$domain,
    ];

    foreach ($needles as $label => $needle) {
        $component = Livewire::test(CrmContacts::class)
            ->set('email', $needle)
            ->call('search');

        expect($component->get('selectedContactId'))->toBeNull("{$label} must not resolve a contact")
            ->and($component->get('searchMissed'))->toBeTrue("{$label} must report a miss");
    }
});

it('never resolves an anonymized contact and never renders a former address', function () {
    $anonymized = Fx::contact('anonymized', 'verified_account');

    // P6-A0's state CHECK makes the address physically NULL: there is nothing left to
    // reveal, so this is not masking — it is absence.
    expect(Fx::owner()->selectOne('SELECT email FROM crm_contacts WHERE id = ?', [$anonymized])->email)->toBeNull();

    test()->actingAs(Admin::admin());

    Livewire::test(CrmContacts::class)
        ->set('email', 'former-address@example.com')
        ->call('search')
        ->assertSet('selectedContactId', null)
        ->assertSet('searchMissed', true);

    // Selecting it directly shows the anonymized label, never a reconstructed address.
    Livewire::test(CrmContacts::class)
        ->call('select', $anonymized)
        ->assertSee('Anonymisé')
        ->assertDontSee('@example.com');
});

it('clears the needle without falling back to a looser lookup', function () {
    $contactId = Fx::contact();

    test()->actingAs(Admin::admin());

    Livewire::test(CrmContacts::class)
        ->call('select', $contactId)
        ->set('email', 'nobody@example.com')
        ->call('search')
        ->assertSet('selectedContactId', null)
        ->call('clearSearch')
        ->assertSet('email', '')
        ->assertSet('searchMissed', false)
        ->assertSet('selectedContactId', null);
});

/**
 * An e-mail address must never reach a URL, a browser history entry, a referrer header
 * or a web-server access log. Livewire only syncs a property to the query string when
 * it carries #[Url]; the absence of that attribute is the actual control, so it is
 * asserted structurally rather than by inspecting a rendered page.
 */
it('keeps the searched address out of the query string', function () {
    // Asserted structurally, not by grepping the source: the class docblock legitimately
    // MENTIONS #[Url] to explain its absence, and a text search cannot tell an
    // explanatory comment from a real attribute. Reflection can.
    $reflection = new ReflectionClass(CrmContacts::class);
    $declared = array_filter(
        $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
        // Scoped to what THIS page declares. Filament's own inherited `$defaultAction`
        // legitimately syncs to the URL, but it carries an action NAME, never CRM data;
        // asserting over inherited machinery would test the framework, not this gate.
        static fn (ReflectionProperty $p): bool => $p->getDeclaringClass()->getName() === CrmContacts::class,
    );

    expect($declared)->not->toBeEmpty();

    foreach ($declared as $property) {
        expect($property->getAttributes(Url::class))
            ->toBe([], "declared property \${$property->getName()} must not sync to the URL");
    }

    // The blade form posts through Livewire; there is no GET form and no query param.
    $view = file_get_contents(resource_path('views/filament/pages/crm-contacts.blade.php'));
    expect($view)->not->toContain('method="get"')
        ->and($view)->not->toContain('?email=');
});

it('refuses to search at all when the CRM foundation flag is off', function () {
    $contactId = Fx::contact();
    $email = Admin::emailOf($contactId);

    test()->actingAs(Admin::admin());

    // The address resolves while the foundation is on…
    expect(app(CrmAdminReadService::class)->findContactByExactEmail($email))
        ->not->toBeNull();

    config(['crm.foundation_enabled' => false]);

    // …and the whole surface closes when it is off: the page refuses access AND the
    // read service refuses independently, so a stale component cannot keep reading.
    expect(CrmContacts::canAccess())->toBeFalse()
        ->and(fn () => app(CrmAdminReadService::class)->findContactByExactEmail($email))
        ->toThrow(CrmOperationException::class);

    // An invalid flag closes the door too, never opens it.
    config(['crm.foundation_enabled' => 'yes']);
    expect(CrmContacts::canAccess())->toBeFalse();
});
