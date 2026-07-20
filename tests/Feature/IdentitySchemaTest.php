<?php

use App\Enums\LifecycleStage;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

it('runs P1 identity tests against PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('has the citext extension and P1 identity tables', function () {
    $extensionExists = DB::table('pg_extension')
        ->where('extname', 'citext')
        ->exists();

    expect($extensionExists)->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('customer_profiles'))->toBeTrue()
        ->and(Schema::hasTable('visitors'))->toBeTrue();
});

it('has the expected P1 columns', function () {
    expect(Schema::hasColumns('users', [
        'id',
        'email',
        'password_hash',
        'role',
        'status',
        'email_verified_at',
        'last_login_at',
        'deleted_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('customer_profiles', [
            'user_id',
            'first_name',
            'last_name',
            'marketing_consent',
            'lifecycle_stage',
            'orders_count',
            'lifetime_value_minor',
            'first_touch_source',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('visitors', [
            'id',
            'user_id',
            'first_seen_at',
            'last_seen_at',
            'first_touch_source',
            'first_landing_page',
            'country_code',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('enforces case-insensitive unique emails through citext', function () {
    User::factory()->create(['email' => 'Test@example.com']);

    expect(fn () => User::factory()->create(['email' => 'test@example.com']))
        ->toThrow(QueryException::class);
});

it('stores password_hash as an Argon2id hash and never as plain text', function () {
    $user = User::factory()->create(['password_hash' => 'plain-secret']);

    expect($user->getRawOriginal('password_hash'))
        ->not->toBe('plain-secret')
        ->toStartWith('$argon2id$')
        ->and(Hash::check('plain-secret', $user->getRawOriginal('password_hash')))->toBeTrue()
        ->and($user->getAuthPasswordName())->toBe('password_hash');
});

it('rejects invalid user roles', function () {
    expect(fn () => DB::table('users')->insert([
        'email' => 'role-check@example.com',
        'password_hash' => Hash::make('password'),
        'role' => 'owner',
        'status' => UserStatus::Active->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects invalid user statuses', function () {
    expect(fn () => DB::table('users')->insert([
        'email' => 'status-check@example.com',
        'password_hash' => Hash::make('password'),
        'role' => UserRole::Customer->value,
        'status' => 'deleted',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects invalid lifecycle stages', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('customer_profiles')->insert([
        'user_id' => $user->id,
        'lifecycle_stage' => 'vip',
        'orders_count' => 0,
        'lifetime_value_minor' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('maps identity relations correctly', function () {
    $user = User::factory()->create([
        'role' => UserRole::Customer,
        'status' => UserStatus::Active,
    ]);

    $profile = CustomerProfile::factory()->create([
        'user_id' => $user->id,
        'lifecycle_stage' => LifecycleStage::Lead,
    ]);

    $visitor = Visitor::factory()->create(['user_id' => $user->id]);

    expect($user->customerProfile->is($profile))->toBeTrue()
        ->and($user->visitors)->toHaveCount(1)
        ->and($visitor->user->is($user))->toBeTrue();
});

it('soft deletes users without physical deletion', function () {
    $user = User::factory()->create();

    $user->delete();

    $this->assertSoftDeleted($user);
    expect(User::withTrashed()->whereKey($user->id)->exists())->toBeTrue();
});

it('allows anonymous visitors and future attachment to a user', function () {
    $visitor = Visitor::factory()->create();

    expect($visitor->user_id)->toBeNull();

    $user = User::factory()->create();
    $visitor->update(['user_id' => $user->id]);

    expect($visitor->refresh()->user->is($user))->toBeTrue();
});

it('does not create out-of-scope P3C, delivery, analytics, or affiliation tables', function () {
    $forbiddenTables = [
        'download_logs',
        'affiliate_profiles',
        'events',
        'analytics_sessions',
        'campaigns',
        'customer_segments',
    ];

    foreach ($forbiddenTables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Unexpected table exists: {$table}");
    }
});
