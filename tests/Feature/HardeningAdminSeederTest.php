<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Durcissement pré-production — H2.3 / H2.4, seeder d'administrateur
|--------------------------------------------------------------------------
|
| Real PostgreSQL. The credentials go through `config()`, not `env()`: the
| seeder reads config precisely so `php artisan config:cache` cannot make it
| refuse on a correctly configured server — and that also makes it testable,
| since Laravel's env repository is immutable while its config is not.
|
*/

const HARDENING_SEED_EMAIL = 'operations@digitrove.test';

const HARDENING_SEED_PASSWORD = 'un-mot-de-passe-suffisamment-long';

function hardeningSeedWith(?string $email, ?string $password): void
{
    config(['admin.email' => $email, 'admin.password' => $password]);

    (new AdminSeeder)->run();
}

it('creates an administrator that can actually reach the panel', function (): void {
    hardeningSeedWith(HARDENING_SEED_EMAIL, HARDENING_SEED_PASSWORD);

    $admin = User::query()->where('email', HARDENING_SEED_EMAIL)->sole();

    // ⚠️ The whole reason UserFactory is not used: it defaults to `role => Customer` and
    // stamps `email_verified_at`. Leaning on it would have produced a VERIFIED CUSTOMER
    // while looking like success — a row exists, the seeder returns cleanly, and nobody can
    // log in. `canAccessPanel` is the only assertion that catches that.
    expect($admin->role)->toBe(UserRole::Admin)
        ->and($admin->status)->toBe(UserStatus::Active)
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->trashed())->toBeFalse()
        ->and($admin->canAccessPanel(Filament\Facades\Filament::getPanel('admin')))->toBeTrue();
});

it('stores a hash, never the plaintext, and the hash verifies', function (): void {
    hardeningSeedWith(HARDENING_SEED_EMAIL, HARDENING_SEED_PASSWORD);

    $hash = (string) User::query()->where('email', HARDENING_SEED_EMAIL)->sole()->password_hash;

    expect($hash)->not->toBe(HARDENING_SEED_PASSWORD)
        ->and($hash)->not->toContain(HARDENING_SEED_PASSWORD)
        // It must be the real password, not merely *a* hash.
        ->and(Hash::check(HARDENING_SEED_PASSWORD, $hash))->toBeTrue();
});

it('writes NOTHING when the credentials are refused', function (mixed $email, mixed $password): void {
    // Fail-closed means fail-closed: no partial row, no placeholder to fix up later.
    expect(fn () => hardeningSeedWith($email, $password))->toThrow(RuntimeException::class)
        ->and(User::withTrashed()->count())->toBe(0);
})->with([
    'no email' => [null, HARDENING_SEED_PASSWORD],
    'no password' => [HARDENING_SEED_EMAIL, null],
    'both missing' => [null, null],
    'trivial password' => [HARDENING_SEED_EMAIL, 'password1234'],
    'password from the address' => ['boutique@digitrove.test', 'boutique-2026-xyz'],
]);

it('is idempotent: a second run repairs rather than duplicating', function (): void {
    hardeningSeedWith(HARDENING_SEED_EMAIL, HARDENING_SEED_PASSWORD);
    hardeningSeedWith(HARDENING_SEED_EMAIL, HARDENING_SEED_PASSWORD);

    // `users.email` is UNIQUE, so a blind second insert would have failed outright.
    expect(User::withTrashed()->where('email', HARDENING_SEED_EMAIL)->count())->toBe(1);
});

it('promotes, reactivates and restores an account the operator names as administrator', function (): void {
    $existing = User::factory()->create([
        'email' => HARDENING_SEED_EMAIL,
        'role' => UserRole::Customer,
        'status' => UserStatus::Suspended,
    ]);
    $existing->delete();

    hardeningSeedWith(HARDENING_SEED_EMAIL, HARDENING_SEED_PASSWORD);

    $admin = User::query()->where('email', HARDENING_SEED_EMAIL)->sole();

    expect($admin->id)->toBe($existing->id)
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($admin->status)->toBe(UserStatus::Active)
        ->and($admin->trashed())->toBeFalse()
        ->and(Hash::check(HARDENING_SEED_PASSWORD, (string) $admin->password_hash))->toBeTrue();
});

it('matches the address case-insensitively, because users.email is CITEXT', function (): void {
    hardeningSeedWith(HARDENING_SEED_EMAIL, HARDENING_SEED_PASSWORD);
    hardeningSeedWith(strtoupper(HARDENING_SEED_EMAIL), HARDENING_SEED_PASSWORD);

    // A case-sensitive lookup would have attempted a second insert and hit the unique index.
    expect(User::withTrashed()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The account must actually authenticate, not merely look right
|--------------------------------------------------------------------------
|
| ⚠️ WHY THIS AND NOT A BROWSER LOGIN. Typing a password into a form field is
| something I will not do, even one I generated myself for a throwaway database:
| the rule is categorical and does not distinguish test credentials from real
| ones. `Auth::attempt` is the strongest equivalent reachable — it traverses the
| SAME user provider, the same `getAuthPasswordName()` returning `password_hash`,
| and the same hash check that Filament's Livewire login component uses. What a
| browser would add beyond this is the form submission itself, and lot 1 already
| proved that path live: 11/11 Alpine components initialised and the submit
| button bound.
|
*/

it('authenticates with the seeded credentials through the real guard', function (): void {
    hardeningSeedWith(HARDENING_SEED_EMAIL, HARDENING_SEED_PASSWORD);

    expect(Auth::attempt([
        'email' => HARDENING_SEED_EMAIL,
        'password' => HARDENING_SEED_PASSWORD,
    ]))->toBeTrue()
        ->and(Auth::user()?->role)->toBe(UserRole::Admin);
});

it('refuses the wrong password through that same guard', function (): void {
    hardeningSeedWith(HARDENING_SEED_EMAIL, HARDENING_SEED_PASSWORD);

    expect(Auth::attempt([
        'email' => HARDENING_SEED_EMAIL,
        'password' => 'un-mot-de-passe-qui-nest-pas-le-bon',
    ]))->toBeFalse();
});

it('opens the admin panel for the seeded administrator and closes it to a customer', function (): void {
    hardeningSeedWith(HARDENING_SEED_EMAIL, HARDENING_SEED_PASSWORD);
    $admin = User::query()->where('email', HARDENING_SEED_EMAIL)->sole();
    $customer = User::factory()->create();

    $this->actingAs($admin)->get('/admin')->assertSuccessful();
    // The same page must refuse a non-admin: proving the panel opens is only half of it.
    $this->actingAs($customer)->get('/admin')->assertForbidden();
});

it('turns an anonymous visit to the panel away', function (): void {
    $this->get('/admin')->assertRedirect();
});

/*
|--------------------------------------------------------------------------
| H2.4 — the disposable account
|--------------------------------------------------------------------------
*/

it('creates the disposable test account in testing, where that is the point', function (): void {
    config(['admin.email' => HARDENING_SEED_EMAIL, 'admin.password' => HARDENING_SEED_PASSWORD]);

    (new DatabaseSeeder)->run();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeTrue();
});

it('never creates it outside local and testing', function (): void {
    // The defect: `UserFactory` stamps `Hash::make('password')`, `email_verified_at` and
    // `status => Active`. A `db:seed` in production created a REAL, VERIFIED, ACTIVE customer
    // whose password is written in the framework's own source.
    config(['admin.email' => HARDENING_SEED_EMAIL, 'admin.password' => HARDENING_SEED_PASSWORD]);
    app()->detectEnvironment(fn (): string => 'production');

    try {
        (new DatabaseSeeder)->run();

        expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse()
            // The administrator is NOT gated — production is exactly where it is needed.
            ->and(User::query()->where('email', HARDENING_SEED_EMAIL)->exists())->toBeTrue();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});
