<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A2 segment/version lifecycle: monotonic numbering, immutable content from INSERT,
 * publication that only moves forward, and same-segment pointer integrity.
 */
function p6a2Definition(int $value = 1000): array
{
    return Fx::definition([
        'field' => 'commerce.net_revenue_minor',
        'operator' => 'gte',
        'currency' => 'XOF',
        'value' => $value,
    ]);
}

function p6a2CreateSegment(string $name = 'Loyal'): int
{
    return (int) Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', [$name.' '.Str::random(6)])->segment_id;
}

function p6a2CreateVersion(int $segmentId, array $definition): object
{
    return Fx::owner()->selectOne(
        'SELECT * FROM create_crm_segment_version(?, ?::jsonb)',
        [$segmentId, json_encode($definition, JSON_THROW_ON_ERROR)],
    );
}

function p6a2Publish(int $versionId): object
{
    return Fx::owner()->selectOne('SELECT * FROM publish_crm_segment_version(?)', [$versionId]);
}

// ── Segment ─────────────────────────────────────────────────────────────────────

it('creates an active segment with no version or generation pointer', function () {
    $segmentId = p6a2CreateSegment();
    $segment = Fx::owner()->selectOne('SELECT * FROM get_crm_segment(?)', [$segmentId]);

    expect($segment->status)->toBe('active')
        ->and($segment->current_version_id)->toBeNull()
        ->and($segment->current_generation_id)->toBeNull();
});

it('rejects a blank or oversized segment name', function () {
    expect(fn () => Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', ['   ']))->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', [str_repeat('a', 121)]))->toThrow(QueryException::class);
});

// ── Version numbering ───────────────────────────────────────────────────────────

it('numbers versions monotonically from one, per segment', function () {
    $a = p6a2CreateSegment();
    $b = p6a2CreateSegment();

    expect((int) p6a2CreateVersion($a, p6a2Definition(1))->version_number)->toBe(1)
        ->and((int) p6a2CreateVersion($a, p6a2Definition(2))->version_number)->toBe(2)
        ->and((int) p6a2CreateVersion($a, p6a2Definition(3))->version_number)->toBe(3)
        // A different segment restarts at 1.
        ->and((int) p6a2CreateVersion($b, p6a2Definition(1))->version_number)->toBe(1);
});

it('refuses to create a version from an invalid definition', function () {
    $segmentId = p6a2CreateSegment();

    expect(fn () => p6a2CreateVersion($segmentId, ['schema_version' => 1, 'match' => 'all', 'criteria' => []]))
        ->toThrow(QueryException::class);
    expect(fn () => p6a2CreateVersion($segmentId, Fx::definition(['field' => 'orders.total_minor', 'operator' => 'gte', 'currency' => 'XOF', 'value' => 1])))
        ->toThrow(QueryException::class);

    expect((int) Fx::owner()->selectOne('SELECT COUNT(*) AS c FROM crm_segment_versions WHERE segment_id = ?', [$segmentId])->c)->toBe(0);
});

// ── Immutability ────────────────────────────────────────────────────────────────

it('keeps a version definition immutable from INSERT, even while draft', function () {
    $segmentId = p6a2CreateSegment();
    $versionId = (int) p6a2CreateVersion($segmentId, p6a2Definition(1000))->version_id;

    $newDefinition = json_encode(p6a2Definition(9999), JSON_THROW_ON_ERROR);

    expect(fn () => Fx::owner()->update('UPDATE crm_segment_versions SET definition = ?::jsonb WHERE id = ?', [$newDefinition, $versionId]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->update('UPDATE crm_segment_versions SET version_number = 99 WHERE id = ?', [$versionId]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->delete('DELETE FROM crm_segment_versions WHERE id = ?', [$versionId]))
        ->toThrow(QueryException::class);
});

it('refuses to revert a published version to draft or to republish it', function () {
    $segmentId = p6a2CreateSegment();
    $versionId = (int) p6a2CreateVersion($segmentId, p6a2Definition())->version_id;
    p6a2Publish($versionId);

    expect(fn () => Fx::owner()->update("UPDATE crm_segment_versions SET status = 'draft', published_at = NULL WHERE id = ?", [$versionId]))
        ->toThrow(QueryException::class);
    // Publishing twice is refused by the authority itself.
    expect(fn () => p6a2Publish($versionId))->toThrow(QueryException::class);
});

// ── Publication ordering ────────────────────────────────────────────────────────

it('publishes a version and points the segment at it', function () {
    $segmentId = p6a2CreateSegment();
    $versionId = (int) p6a2CreateVersion($segmentId, p6a2Definition())->version_id;

    expect(p6a2Publish($versionId)->status)->toBe('published');

    $segment = Fx::owner()->selectOne('SELECT * FROM get_crm_segment(?)', [$segmentId]);
    expect((int) $segment->current_version_id)->toBe($versionId)
        ->and((int) $segment->current_version_number)->toBe(1);
});

it('never republishes an older version over a newer one', function () {
    $segmentId = p6a2CreateSegment();
    $v1 = (int) p6a2CreateVersion($segmentId, p6a2Definition(1))->version_id;
    $v2 = (int) p6a2CreateVersion($segmentId, p6a2Definition(2))->version_id;

    p6a2Publish($v2);   // publish the newer one first

    // v1 is older: publishing it now would move the segment backwards.
    expect(fn () => p6a2Publish($v1))->toThrow(QueryException::class);

    expect((int) Fx::owner()->selectOne('SELECT * FROM get_crm_segment(?)', [$segmentId])->current_version_number)->toBe(2);
});

it('refuses to publish a new version while a generation is building', function () {
    $segmentId = p6a2CreateSegment();
    $v1 = (int) p6a2CreateVersion($segmentId, p6a2Definition(1))->version_id;
    p6a2Publish($v1);

    Fx::owner()->selectOne('SELECT * FROM start_crm_segment_generation(?, ?)', [$segmentId, 100]);

    $v2 = (int) p6a2CreateVersion($segmentId, p6a2Definition(2))->version_id;
    expect(fn () => p6a2Publish($v2))->toThrow(QueryException::class);
});

// ── Same-segment pointer integrity (database level) ─────────────────────────────

it('makes it structurally impossible to point a segment at another segment version', function () {
    $a = p6a2CreateSegment();
    $b = p6a2CreateSegment();
    $versionOfB = (int) p6a2CreateVersion($b, p6a2Definition())->version_id;

    // The composite FK (current_version_id, id) -> (id, segment_id) rejects this.
    expect(fn () => Fx::owner()->update('UPDATE crm_segments SET current_version_id = ? WHERE id = ?', [$versionOfB, $a]))
        ->toThrow(QueryException::class);
});

it('makes it structurally impossible to build a generation from another segment version', function () {
    $a = p6a2CreateSegment();
    $b = p6a2CreateSegment();
    $versionOfB = (int) p6a2CreateVersion($b, p6a2Definition())->version_id;

    expect(fn () => Fx::owner()->insert(
        "INSERT INTO crm_segment_generations (segment_id, segment_version_id, contact_id_high_water_mark, batch_size, status, members_count, created_at, updated_at) VALUES (?, ?, 0, 10, 'ready', 0, NOW(), NOW())",
        [$a, $versionOfB],
    ))->toThrow(QueryException::class);
});
