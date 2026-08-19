<?php

declare(strict_types=1);

use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

function p6a2Constraint(string $name): bool
{
    return (bool) Fx::owner()->selectOne(
        'SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conname = ?) AS present',
        [$name],
    )->present;
}

function p6a2Columns(string $table): array
{
    $columns = array_map(
        static fn (object $c): string => $c->column_name,
        Fx::owner()->select('SELECT column_name FROM information_schema.columns WHERE table_name = ?', [$table]),
    );
    sort($columns);

    return $columns;
}

it('adds exactly one migration (000025) and no 000026', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(51)
        ->and(glob($root.'/database/migrations/2026_07_14_000025*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000036*.php'))->toBe([]);
});

it('creates exactly the four segment tables', function () {
    foreach ([
        'crm_segments',
        'crm_segment_versions',
        'crm_segment_generations',
        'crm_segment_generation_members',
    ] as $table) {
        expect(Fx::owner()->selectOne("SELECT to_regclass('public.".$table."') AS present")->present)->not->toBeNull();
    }

    // No fifth table was smuggled in.
    $segmentTables = array_map(
        static fn (object $t): string => $t->tablename,
        Fx::owner()->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'crm_segment%' ORDER BY tablename"),
    );
    expect($segmentTables)->toBe([
        'crm_segment_generation_members',
        'crm_segment_generations',
        'crm_segment_versions',
        'crm_segments',
    ]);
});

it('stores membership as contact ids only, with no PII and no metric', function () {
    expect(p6a2Columns('crm_segment_generation_members'))->toBe(['contact_id', 'generation_id']);

    foreach (['email', 'name', 'phone', 'user_id', 'visitor_id', 'reason', 'score', 'amount_minor'] as $forbidden) {
        expect(p6a2Columns('crm_segment_generation_members'))->not->toContain($forbidden);
    }
});

it('keeps no PII on segments, versions or generations', function () {
    foreach (['crm_segments', 'crm_segment_versions', 'crm_segment_generations'] as $table) {
        foreach (['email', 'customer_email', 'phone', 'visitor_id'] as $forbidden) {
            expect(p6a2Columns($table))->not->toContain($forbidden);
        }
    }
});

it('enforces the documented check constraints', function () {
    foreach ([
        'crm_segments_name_check',
        'crm_segments_status_check',
        'crm_segment_versions_number_check',
        'crm_segment_versions_schema_version_check',
        'crm_segment_versions_status_check',
        'crm_segment_versions_status_dates_check',
        'crm_segment_versions_definition_object_check',
        'crm_segment_versions_definition_size_check',
        'crm_segment_generations_hwm_check',
        'crm_segment_generations_batch_size_check',
        'crm_segment_generations_members_count_check',
        'crm_segment_generations_status_check',
        'crm_segment_generations_error_code_check',
        'crm_segment_generations_status_dates_check',
    ] as $constraint) {
        expect(p6a2Constraint($constraint))->toBeTrue();
    }
});

it('enforces same-segment pointers with composite foreign keys', function () {
    foreach ([
        'crm_segments_current_version_same_segment_foreign',
        'crm_segments_current_generation_same_segment_foreign',
        'crm_segment_generations_version_same_segment_foreign',
        'crm_segment_versions_id_segment_unique',
        'crm_segment_generations_id_segment_unique',
        'crm_segment_versions_segment_number_unique',
    ] as $constraint) {
        expect(p6a2Constraint($constraint))->toBeTrue();
    }
});

it('keys membership on (generation_id, contact_id) and allows one active generation per segment', function () {
    $pk = array_map(
        static fn (object $r): string => $r->attname,
        Fx::owner()->select("SELECT a.attname FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY(c.conkey) WHERE c.conname = 'crm_segment_generation_members_pkey' ORDER BY a.attnum"),
    );
    expect($pk)->toBe(['generation_id', 'contact_id']);

    expect((bool) Fx::owner()->selectOne(
        "SELECT EXISTS(SELECT 1 FROM pg_indexes WHERE indexname = 'crm_segment_generations_single_active') AS present",
    )->present)->toBeTrue();
});

it('installs the segment authorities and owns every object with the CRM executor', function () {
    foreach ([
        'validate_crm_segment_definition_v1',
        'crm_segment_contact_matches_v1',
        'create_crm_segment',
        'create_crm_segment_version',
        'publish_crm_segment_version',
        'start_crm_segment_generation',
        'process_crm_segment_generation_batch',
        'retry_crm_segment_generation',
        'get_crm_segment',
        'list_crm_segments',
        'get_crm_segment_generation',
        'list_due_crm_segment_generations',
        'list_crm_segment_current_members',
    ] as $function) {
        expect((bool) Fx::owner()->selectOne('SELECT EXISTS(SELECT 1 FROM pg_proc WHERE proname = ?) AS present', [$function])->present)->toBeTrue();
    }

    foreach ([
        'crm_segments',
        'crm_segment_versions',
        'crm_segment_generations',
        'crm_segment_generation_members',
    ] as $table) {
        expect((string) Fx::owner()->selectOne('SELECT tableowner FROM pg_tables WHERE tablename = ?', [$table])->tableowner)
            ->toBe('digitrove_crm_executor');
    }
});
