<?php

use App\Models\AnalyticsSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;

uses(RefreshDatabase::class);

function p5a0SessionAttributes(array $overrides = []): array
{
    $startedAt = now()->subMinutes(5);

    return array_merge([
        'id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
        'user_id' => null,
        'started_at' => $startedAt,
        'last_seen_at' => $startedAt->addMinutes(2),
        'ended_at' => null,
        'entry_path' => '/catalog',
        'exit_path' => null,
        'page_views' => 1,
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'launch_2026',
        'device_type' => 'mobile',
        'country_code' => 'CI',
        'created_at' => $startedAt->addMinutes(3),
        'updated_at' => $startedAt->addMinutes(3),
    ], $overrides);
}

function expectP5A0SessionViolation(array $attributes, string $constraint): void
{
    $exception = null;

    try {
        DB::transaction(fn () => DB::table('analytics_sessions')->insert(p5a0SessionAttributes($attributes)));
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('23514')
        ->and($exception->getMessage())->toContain($constraint);
}

it('creates analytics sessions with soft identities, native types, and no foreign keys', function () {
    expect(Schema::hasTable('analytics_sessions'))->toBeTrue()
        ->and(Schema::hasColumns('analytics_sessions', [
            'id', 'visitor_id', 'user_id', 'started_at', 'last_seen_at', 'ended_at',
            'entry_path', 'exit_path', 'page_views', 'utm_source', 'utm_medium',
            'utm_campaign', 'device_type', 'country_code', 'created_at', 'updated_at',
        ]))->toBeTrue();

    $columns = DB::table('information_schema.columns')
        ->select('column_name', 'data_type', 'udt_name', 'character_maximum_length', 'is_nullable')
        ->where('table_schema', 'public')
        ->where('table_name', 'analytics_sessions')
        ->get()
        ->keyBy('column_name');

    expect($columns['id']->data_type)->toBe('uuid')
        ->and($columns['visitor_id']->data_type)->toBe('uuid')
        ->and($columns['visitor_id']->is_nullable)->toBe('NO')
        ->and($columns['user_id']->data_type)->toBe('bigint')
        ->and($columns['user_id']->is_nullable)->toBe('YES')
        ->and($columns['started_at']->data_type)->toBe('timestamp with time zone')
        ->and($columns['entry_path']->character_maximum_length)->toBe(2048)
        ->and(DB::table('pg_constraint')->where('conrelid', DB::raw("'public.analytics_sessions'::regclass"))->where('contype', 'f')->count())->toBe(0);
});

it('accepts coherent session timestamps and rejects each invalid ordering', function () {
    $completedStart = now()->subMinutes(5);
    DB::table('analytics_sessions')->insert(p5a0SessionAttributes([
        'started_at' => $completedStart,
        'last_seen_at' => $completedStart->addMinutes(2),
        'ended_at' => $completedStart->addMinutes(3),
    ]));

    $started = now()->toImmutable();
    expectP5A0SessionViolation(['started_at' => $started, 'last_seen_at' => $started->subSecond()], 'analytics_sessions_time_order_check');
    expectP5A0SessionViolation(['started_at' => $started, 'last_seen_at' => $started, 'ended_at' => $started->subSecond()], 'analytics_sessions_time_order_check');
    expectP5A0SessionViolation([
        'started_at' => $started,
        'last_seen_at' => $started,
        'created_at' => $started->subSecond(),
    ], 'analytics_sessions_created_after_start_check');
    expectP5A0SessionViolation([
        'started_at' => $started,
        'last_seen_at' => $started,
        'created_at' => $started,
        'updated_at' => $started->subSecond(),
    ], 'analytics_sessions_updated_after_created_check');
});

it('rejects invalid counters, paths, UTM values, device types, and country codes', function () {
    expectP5A0SessionViolation(['page_views' => -1], 'analytics_sessions_page_views_non_negative_check');
    expectP5A0SessionViolation(['entry_path' => 'https://example.test/catalog'], 'analytics_sessions_entry_path_format_check');
    expectP5A0SessionViolation(['exit_path' => '/catalog#fragment'], 'analytics_sessions_exit_path_format_check');
    expectP5A0SessionViolation(['utm_source' => 'Paid Search'], 'analytics_sessions_utm_source_format_check');
    expectP5A0SessionViolation(['device_type' => 'smart-fridge'], 'analytics_sessions_device_type_check');
    expectP5A0SessionViolation(['country_code' => 'ci'], 'analytics_sessions_country_code_format_check');
});

it('contains no raw network or cookie identity and exposes a valid factory', function () {
    foreach (['ip', 'ip_address', 'ip_hash', 'cookie', 'cookie_id', 'session_token', 'token'] as $column) {
        expect(Schema::hasColumn('analytics_sessions', $column))->toBeFalse("forbidden analytics session column: {$column}");
    }

    $session = AnalyticsSession::factory()->make();

    expect($session->id)->toBeString()
        ->and($session->visitor_id)->toBeString()
        ->and($session->page_views)->toBeGreaterThanOrEqual(0)
        ->and($session->started_at->lessThanOrEqualTo($session->last_seen_at))->toBeTrue();
});
