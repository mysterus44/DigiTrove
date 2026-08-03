<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

it('allows only active non-deleted administrators into the admin panel', function (
    UserRole $role,
    UserStatus $status,
    bool $deleted,
    bool $allowed,
) {
    $user = User::factory()->create(compact('role', 'status'));

    if ($deleted) {
        $user->delete();
    }

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBe($allowed)
        ->and(Gate::forUser($user)->allows('viewGlobalAnalytics'))->toBe($allowed);
})->with([
    'active admin' => [UserRole::Admin, UserStatus::Active, false, true],
    'active staff' => [UserRole::Staff, UserStatus::Active, false, false],
    'active customer' => [UserRole::Customer, UserStatus::Active, false, false],
    'suspended admin' => [UserRole::Admin, UserStatus::Suspended, false, false],
    'blocked admin' => [UserRole::Admin, UserStatus::Blocked, false, false],
    'deleted admin' => [UserRole::Admin, UserStatus::Active, true, false],
]);

it('enforces the Filament boundary over HTTP', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
    ]);
    $staff = User::factory()->create([
        'role' => UserRole::Staff,
        'status' => UserStatus::Active,
    ]);

    $this->actingAs($admin)->get('/admin')->assertSuccessful();
    auth()->logout();
    $this->actingAs($staff)->get('/admin')->assertForbidden();
});

it('refuses an active administrator on any non-admin panel', function () {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
    ]);

    expect($admin->canAccessPanel(Panel::make()->id('other')))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewGlobalAnalytics'))->toBeTrue();
});
