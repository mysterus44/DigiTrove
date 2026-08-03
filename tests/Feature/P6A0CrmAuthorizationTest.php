<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

it('allows only active non-deleted administrators to manage CRM', function (
    UserRole $role,
    UserStatus $status,
    bool $deleted,
    bool $allowed,
) {
    $user = User::factory()->create(compact('role', 'status'));

    if ($deleted) {
        $user->delete();
    }

    expect(Gate::forUser($user)->allows('manageCustomerRelationships'))->toBe($allowed);
})->with([
    'active admin' => [UserRole::Admin, UserStatus::Active, false, true],
    'active staff' => [UserRole::Staff, UserStatus::Active, false, false],
    'active customer' => [UserRole::Customer, UserStatus::Active, false, false],
    'suspended admin' => [UserRole::Admin, UserStatus::Suspended, false, false],
    'blocked admin' => [UserRole::Admin, UserStatus::Blocked, false, false],
    'deleted admin' => [UserRole::Admin, UserStatus::Active, true, false],
]);

it('keeps CRM authorization independent from global analytics', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Active]);

    expect(Gate::forUser($admin)->has('manageCustomerRelationships'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('manageCustomerRelationships'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('viewGlobalAnalytics'))->toBeTrue();
});
