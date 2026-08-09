<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Pages\CrmContacts;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['crm.foundation_enabled' => true]);
});

/**
 * P6-B0 authorization. Hiding navigation is not a control: every assertion here goes
 * through the server-side gate, and the HTTP checks hit the page route directly.
 */
it('admits only an active, non-deleted administrator to the CRM pages', function (
    UserRole $role,
    UserStatus $status,
    bool $deleted,
    bool $allowed,
) {
    $user = User::factory()->create(compact('role', 'status'));

    if ($deleted) {
        $user->delete();
    }

    $this->actingAs($user);

    expect(Gate::forUser($user)->allows('manageCustomerRelationships'))->toBe($allowed)
        ->and(CrmContacts::canAccess())->toBe($allowed)
        // Navigation follows access, never the reverse.
        ->and(CrmContacts::shouldRegisterNavigation())->toBe($allowed);
})->with([
    'active admin' => [UserRole::Admin, UserStatus::Active, false, true],
    'active staff' => [UserRole::Staff, UserStatus::Active, false, false],
    'active customer' => [UserRole::Customer, UserStatus::Active, false, false],
    'suspended admin' => [UserRole::Admin, UserStatus::Suspended, false, false],
    'blocked admin' => [UserRole::Admin, UserStatus::Blocked, false, false],
    'deleted admin' => [UserRole::Admin, UserStatus::Active, true, false],
]);

it('refuses a guest', function () {
    expect(CrmContacts::canAccess())->toBeFalse();
});

it('enforces the boundary over HTTP on the CRM contacts page', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    $staff = User::factory()->create(['role' => UserRole::Staff, 'status' => UserStatus::Active]);
    $customer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);

    $this->actingAs($admin)->get('/admin/crm-contacts')->assertSuccessful();

    auth()->logout();
    $this->actingAs($staff)->get('/admin/crm-contacts')->assertForbidden();

    auth()->logout();
    $this->actingAs($customer)->get('/admin/crm-contacts')->assertForbidden();

    // A guest is redirected to login, never served CRM data.
    auth()->logout();
    $this->get('/admin/crm-contacts')->assertRedirect();
});

it('fails closed when the CRM foundation flag is disabled or malformed', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);
    $this->actingAs($admin);

    config(['crm.foundation_enabled' => false]);
    expect(CrmContacts::canAccess())->toBeFalse();

    // An invalid flag must close the door, never open it.
    config(['crm.foundation_enabled' => 'yes']);
    expect(CrmContacts::canAccess())->toBeFalse();
});
