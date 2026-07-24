<?php

use App\Models\AnalyticsEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;

uses(RefreshDatabase::class);

function p5a0EventAttributes(array $overrides = []): array
{
    $occurredAt = now()->subSecond();

    return array_merge([
        'public_id' => (string) Str::uuid(),
        'occurred_at' => $occurredAt,
        'visitor_id' => (string) Str::uuid(),
        'user_id' => 42,
        'session_id' => (string) Str::uuid(),
        'event_name' => 'product_view',
        'entity_type' => 'product',
        'entity_id' => 7,
        'properties' => json_encode(['placement' => 'catalog'], JSON_THROW_ON_ERROR),
        'page_path' => '/products/catalog-item',
        'referrer_host' => 'example.test',
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'launch_2026',
        'device_type' => 'desktop',
        'country_code' => 'CI',
        'ip_hash' => str_repeat('a', 64),
        'ip_hash_key_version' => 1,
        'created_at' => $occurredAt->addSecond(),
    ], $overrides);
}

function expectP5A0EventViolation(array $attributes, string $expected, string $sqlState = '23514'): void
{
    $exception = null;

    try {
        DB::transaction(fn () => DB::table('events')->insert(p5a0EventAttributes($attributes)));
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe($sqlState)
        ->and($exception->getMessage())->toContain($expected);
}

it('creates a range-partitioned events parent with one deterministic default partition', function () {
    expect(Schema::hasTable('events'))->toBeTrue()
        ->and(Schema::hasTable('events_default'))->toBeTrue();

    $partition = DB::selectOne(<<<'SQL'
        SELECT pg_get_partkeydef(c.oid) AS key
        FROM pg_partitioned_table p
        JOIN pg_class c ON c.oid = p.partrelid
        WHERE c.oid = 'public.events'::regclass
        SQL);

    $children = DB::select(<<<'SQL'
        SELECT child.relname, pg_get_expr(child.relpartbound, child.oid) AS bound
        FROM pg_inherits i
        JOIN pg_class parent ON parent.oid = i.inhparent
        JOIN pg_class child ON child.oid = i.inhrelid
        WHERE parent.oid = 'public.events'::regclass
        ORDER BY child.relname
        SQL);

    expect($partition->key)->toBe('RANGE (occurred_at)')
        ->and($children)->toHaveCount(1)
        ->and($children[0]->relname)->toBe('events_default')
        ->and($children[0]->bound)->toBe('DEFAULT');
});

it('pins events columns, native types, composite identity, indexes, and absence of foreign keys', function () {
    $columns = DB::table('information_schema.columns')
        ->select('column_name', 'data_type', 'udt_name', 'character_maximum_length')
        ->where('table_schema', 'public')
        ->where('table_name', 'events')
        ->get()
        ->keyBy('column_name');

    expect($columns->keys()->all())->toBe([
        'id', 'public_id', 'occurred_at', 'visitor_id', 'user_id', 'session_id',
        'event_name', 'entity_type', 'entity_id', 'properties', 'page_path',
        'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'device_type',
        'country_code', 'ip_hash', 'ip_hash_key_version', 'created_at',
    ])
        ->and($columns['id']->data_type)->toBe('bigint')
        ->and($columns['public_id']->data_type)->toBe('uuid')
        ->and($columns['occurred_at']->data_type)->toBe('timestamp with time zone')
        ->and($columns['properties']->data_type)->toBe('jsonb')
        ->and($columns['event_name']->character_maximum_length)->toBe(64)
        ->and($columns['ip_hash']->character_maximum_length)->toBe(64);

    $constraints = DB::table('pg_constraint')
        ->where('conrelid', DB::raw("'public.events'::regclass"))
        ->pluck('contype', 'conname');

    $primary = DB::selectOne(<<<'SQL'
        SELECT pg_get_constraintdef(oid) AS definition
        FROM pg_constraint
        WHERE conrelid = 'public.events'::regclass AND contype = 'p'
        SQL);

    $indexes = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'events')
        ->pluck('indexname');

    expect($constraints->filter(fn (string $type): bool => $type === 'f'))->toBeEmpty()
        ->and($primary->definition)->toBe('PRIMARY KEY (id, occurred_at)')
        ->and($indexes)->toContain(
            'events_public_id_occurred_at_unique',
            'events_event_name_occurred_at_index',
            'events_visitor_id_occurred_at_index',
            'events_session_id_occurred_at_index',
            'events_entity_occurred_at_index',
            'events_utm_campaign_occurred_at_index',
        )
        ->and($indexes->contains(fn (string $name): bool => str_contains($name, 'properties')))->toBeFalse();
});

it('routes a valid event to the default partition and exposes an honest read-only model factory', function () {
    $attributes = p5a0EventAttributes();
    DB::table('events')->insert($attributes);

    $row = DB::selectOne(
        'SELECT tableoid::regclass::text AS partition FROM events WHERE public_id = ? AND occurred_at = ?',
        [$attributes['public_id'], $attributes['occurred_at']],
    );

    $factory = AnalyticsEvent::factory()->make();

    expect($row->partition)->toBe('events_default')
        ->and($factory->properties)->toBeArray()
        ->and($factory->getIncrementing())->toBeTrue()
        ->and($factory->getKeyName())->toBe('id')
        ->and($factory->toArray())->not->toHaveKey('ip_hash');
});

it('rejects malformed event names, entity references, and chronology with exact checks', function () {
    expectP5A0EventViolation(['event_name' => 'ProductView'], 'events_event_name_format_check');
    expectP5A0EventViolation(['event_name' => 'product view'], 'events_event_name_format_check');
    expectP5A0EventViolation(['event_name' => str_repeat('a', 65)], 'value too long', '22001');
    expectP5A0EventViolation(['entity_type' => 'product', 'entity_id' => null], 'events_entity_reference_consistency_check');
    expectP5A0EventViolation(['entity_type' => null, 'entity_id' => 7], 'events_entity_reference_consistency_check');
    expectP5A0EventViolation(['created_at' => now()->subMinute()], 'events_created_after_occurrence_check');
});

it('accepts only bounded JSON objects and privacy-safe paths and attribution fields', function () {
    expectP5A0EventViolation(['properties' => json_encode(['array'], JSON_THROW_ON_ERROR)], 'events_properties_object_check');
    expectP5A0EventViolation(['properties' => json_encode('scalar', JSON_THROW_ON_ERROR)], 'events_properties_object_check');
    expectP5A0EventViolation(
        ['properties' => json_encode(['payload' => str_repeat('x', 17000)], JSON_THROW_ON_ERROR)],
        'events_properties_size_check',
    );
    expectP5A0EventViolation(['page_path' => 'https://example.test/products/1'], 'events_page_path_format_check');
    expectP5A0EventViolation(['page_path' => '/products/1#secret'], 'events_page_path_format_check');
    expectP5A0EventViolation(['page_path' => "/products/1\r\nx"], 'events_page_path_format_check');
    expectP5A0EventViolation(['referrer_host' => 'https://example.test'], 'events_referrer_host_format_check');
    expectP5A0EventViolation(['country_code' => 'ci'], 'events_country_code_format_check');
    expectP5A0EventViolation(['ip_hash' => null, 'ip_hash_key_version' => 1], 'events_ip_hash_consistency_check');
    expectP5A0EventViolation(['ip_hash' => str_repeat('A', 64)], 'events_ip_hash_consistency_check');
});

it('is append-only for owner and migrator operations', function () {
    $attributes = p5a0EventAttributes();
    DB::table('events')->insert($attributes);

    foreach ([
        ['UPDATE events SET event_name = ? WHERE public_id = ?', ['cart_view', $attributes['public_id']]],
        ['DELETE FROM events WHERE public_id = ?', [$attributes['public_id']]],
    ] as [$sql, $bindings]) {
        $exception = null;

        try {
            DB::transaction(fn () => DB::statement($sql, $bindings));
        } catch (QueryException $queryException) {
            $exception = $queryException;
        }

        expect($exception)->not->toBeNull()
            ->and((string) $exception->getCode())->toBe('23514')
            ->and($exception->getMessage())->toContain('analytics events are append-only');
    }

    expect(DB::table('events')->count())->toBe(1);
});
