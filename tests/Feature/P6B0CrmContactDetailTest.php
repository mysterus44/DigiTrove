<?php

declare(strict_types=1);

use App\Filament\Pages\CrmContacts;
use App\Services\Crm\CrmAdminReadService;
use App\Services\Crm\CrmOperationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CrmAdminFixtures as Admin;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

it('renders the identity block of a selected contact', function () {
    $contactId = Fx::contact('active', 'verified_account');
    $email = Admin::emailOf($contactId);

    test()->actingAs(Admin::admin());

    Livewire::test(CrmContacts::class)
        ->call('select', $contactId)
        ->assertSee('Identité')
        ->assertSee($email)
        ->assertSee('verified_account');
});

it('exposes an anonymized contact as a null address, never a masked one', function () {
    $anonymized = Fx::contact('anonymized', 'guest_order');

    test()->actingAs(Admin::admin());

    $contact = app(CrmAdminReadService::class)->contact($anonymized);

    expect($contact)->not->toBeNull()
        ->and($contact['email'])->toBeNull()
        ->and($contact['status'])->toBe('anonymized')
        ->and($contact['anonymized_at'])->not->toBeNull();

    // No '***@***' style masking exists: a mask implies a hidden value, and there is
    // none. The label is a plain state word.
    Livewire::test(CrmContacts::class)
        ->call('select', $anonymized)
        ->assertSee('Anonymisé')
        ->assertDontSee('***');
});

it('returns null for a contact that does not exist', function () {
    test()->actingAs(Admin::admin());

    expect(app(CrmAdminReadService::class)->contact(999_999))->toBeNull();
});

it('refuses an invalid contact identifier at the authority', function () {
    test()->actingAs(Admin::admin());

    // Sanitized: the admin sees a CRM-level failure, never the PostgreSQL 22023.
    expect(fn () => app(CrmAdminReadService::class)->contact(0))->toThrow(CrmOperationException::class)
        ->and(fn () => app(CrmAdminReadService::class)->contact(-1))->toThrow(CrmOperationException::class);
});

it('never renders a raw database message on the detail screen', function () {
    test()->actingAs(Admin::admin());

    $page = file_get_contents(app_path('Filament/Pages/CrmContacts.php'));
    $view = file_get_contents(resource_path('views/filament/pages/crm-contacts.blade.php'));

    // A driver message can carry table names, column names and fragments of data.
    foreach (['SQLSTATE', 'getMessage()', 'QueryException'] as $token) {
        expect($page)->not->toContain($token)
            ->and($view)->not->toContain($token);
    }
});
