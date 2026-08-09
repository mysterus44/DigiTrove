<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A2 generation lifecycle: bounded keyset materialisation, cursor on the last
 * SCANNED contact, atomic publication of the current generation, failure atomicity and
 * explicit retry.
 */
function p6a2GenDefinition(int $threshold = 1000): array
{
    return Fx::definition([
        'field' => 'commerce.net_revenue_minor',
        'operator' => 'gte',
        'currency' => 'XOF',
        'value' => $threshold,
    ]);
}

function p6a2Start(int $segmentId, int $batchSize = 100): object
{
    return Fx::owner()->selectOne('SELECT * FROM start_crm_segment_generation(?, ?)', [$segmentId, $batchSize]);
}

function p6a2Batch(int $generationId): object
{
    return Fx::owner()->selectOne('SELECT * FROM process_crm_segment_generation_batch(?)', [$generationId]);
}

function p6a2Generation(int $generationId): ?object
{
    return Fx::owner()->selectOne('SELECT * FROM get_crm_segment_generation(?)', [$generationId]);
}

// ── Start ───────────────────────────────────────────────────────────────────────

it('starts a generation that freezes the contact high-water mark', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 5000);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2GenDefinition());

    $generation = p6a2Start($segmentId);

    expect((int) $generation->contact_id_high_water_mark)->toBeGreaterThanOrEqual($contactId)
        ->and($generation->status)->toBe('ready');

    $row = p6a2Generation((int) $generation->generation_id);
    expect($row->cursor_contact_id)->toBeNull()
        ->and((int) $row->members_count)->toBe(0);
});

it('refuses to start without a published version, on an unknown segment or out of bounds', function () {
    $segmentId = (int) Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', ['No version'])->segment_id;

    expect(fn () => p6a2Start($segmentId))->toThrow(QueryException::class);
    expect(fn () => p6a2Start(999999))->toThrow(QueryException::class);

    ['segment_id' => $published] = Fx::createSegmentWithVersion(p6a2GenDefinition());
    expect(fn () => p6a2Start($published, 0))->toThrow(QueryException::class);
    expect(fn () => p6a2Start($published, 101))->toThrow(QueryException::class);
});

it('allows at most one active generation per segment but lets other segments build', function () {
    ['segment_id' => $a] = Fx::createSegmentWithVersion(p6a2GenDefinition());
    ['segment_id' => $b] = Fx::createSegmentWithVersion(p6a2GenDefinition());

    p6a2Start($a);
    expect(fn () => p6a2Start($a))->toThrow(QueryException::class);

    // A different segment is unaffected.
    expect(p6a2Start($b)->status)->toBe('ready');
});

// ── Batch processing ────────────────────────────────────────────────────────────

it('materialises only the matching contacts and publishes the generation', function () {
    $rich = Fx::contact();
    Fx::rollup($rich, 'XOF', gross: 50000);
    $poor = Fx::contact();
    Fx::rollup($poor, 'XOF', gross: 10);
    $none = Fx::contact();

    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2GenDefinition(1000));
    $generationId = Fx::buildGeneration($segmentId);

    expect(p6a2Generation($generationId)->status)->toBe('published')
        ->and((int) p6a2Generation($generationId)->members_count)->toBe(1)
        ->and(Fx::currentMembers($segmentId))->toBe([$rich]);
});

it('advances the cursor on the last SCANNED contact even when a batch matches nobody', function () {
    // Three contacts, none matching: the cursor must still move or the run would loop.
    $c1 = Fx::contact();
    $c2 = Fx::contact();
    $c3 = Fx::contact();
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2GenDefinition(1000));

    $generationId = (int) p6a2Start($segmentId, 1)->generation_id;

    $first = p6a2Batch($generationId);
    expect((int) $first->scanned_in_batch)->toBe(1)
        ->and((int) $first->matched_in_batch)->toBe(0)
        ->and($first->status)->toBe('ready')
        ->and((int) p6a2Generation($generationId)->cursor_contact_id)->toBe($c1);

    p6a2Batch($generationId);
    expect((int) p6a2Generation($generationId)->cursor_contact_id)->toBe($c2);

    p6a2Batch($generationId);
    expect((int) p6a2Generation($generationId)->cursor_contact_id)->toBe($c3);

    // Nothing left to scan: the generation publishes with zero members.
    expect(p6a2Batch($generationId)->status)->toBe('published');
    expect((int) p6a2Generation($generationId)->members_count)->toBe(0)
        ->and(Fx::currentMembers($segmentId))->toBe([]);
});

it('resumes across bounded batches and counts members exactly once', function () {
    $matching = [];
    foreach (range(1, 5) as $ignored) {
        $contactId = Fx::contact();
        Fx::rollup($contactId, 'XOF', gross: 20000);
        $matching[] = $contactId;
    }

    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2GenDefinition(1000));
    $generationId = (int) p6a2Start($segmentId, 2)->generation_id;

    p6a2Batch($generationId);
    expect((int) p6a2Generation($generationId)->members_count)->toBe(2);
    p6a2Batch($generationId);
    expect((int) p6a2Generation($generationId)->members_count)->toBe(4);
    p6a2Batch($generationId);
    expect((int) p6a2Generation($generationId)->members_count)->toBe(5);

    expect(p6a2Batch($generationId)->status)->toBe('published');
    expect(Fx::currentMembers($segmentId))->toBe($matching);
});

it('ignores a contact created after the frozen high-water mark', function () {
    $before = Fx::contact();
    Fx::rollup($before, 'XOF', gross: 20000);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2GenDefinition(1000));

    $generationId = (int) p6a2Start($segmentId, 100)->generation_id;

    // Created AFTER the HWM: it belongs to the next generation, not this one.
    $after = Fx::contact();
    Fx::rollup($after, 'XOF', gross: 30000);

    while (p6a2Batch($generationId)->status === 'ready') {
    }

    expect(Fx::currentMembers($segmentId))->toBe([$before]);

    // The next generation picks it up.
    Fx::buildGeneration($segmentId);
    expect(Fx::currentMembers($segmentId))->toBe([$before, $after]);
});

// ── Atomic publication ──────────────────────────────────────────────────────────

it('keeps the previous generation fully visible until the next one is published', function () {
    $first = Fx::contact();
    Fx::rollup($first, 'XOF', gross: 20000);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2GenDefinition(1000));

    Fx::buildGeneration($segmentId);
    expect(Fx::currentMembers($segmentId))->toBe([$first]);

    // Two more matching contacts, then build G2 one contact at a time.
    $second = Fx::contact();
    Fx::rollup($second, 'XOF', gross: 20000);
    $third = Fx::contact();
    Fx::rollup($third, 'XOF', gross: 20000);

    $g2 = (int) p6a2Start($segmentId, 1)->generation_id;

    while (true) {
        $result = p6a2Batch($g2);
        if ($result->status !== 'ready') {
            break;
        }
        // Mid-build, readers still see G1 in full — never a partial G2.
        expect(Fx::currentMembers($segmentId))->toBe([$first]);
    }

    // Published: the whole of G2 becomes visible at once.
    expect(p6a2Generation($g2)->status)->toBe('published')
        ->and(Fx::currentMembers($segmentId))->toBe([$first, $second, $third]);
});

it('freezes a published generation and its membership', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 20000);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2GenDefinition(1000));
    $generationId = Fx::buildGeneration($segmentId);

    $other = Fx::contact();

    expect(fn () => Fx::owner()->insert('INSERT INTO crm_segment_generation_members (generation_id, contact_id) VALUES (?, ?)', [$generationId, $other]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->delete('DELETE FROM crm_segment_generation_members WHERE generation_id = ?', [$generationId]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->update('UPDATE crm_segment_generations SET members_count = 999 WHERE id = ?', [$generationId]))
        ->toThrow(QueryException::class);
});

// ── Failure and retry ───────────────────────────────────────────────────────────

it('reports a missing generation and rejects an invalid identifier', function () {
    expect(p6a2Batch(999999)->status)->toBe('not_found');
    expect(fn () => p6a2Batch(0))->toThrow(QueryException::class);
});

it('fails a batch atomically and resumes only after an explicit retry', function () {
    foreach (range(1, 3) as $ignored) {
        $contactId = Fx::contact();
        Fx::rollup($contactId, 'XOF', gross: 20000);
    }

    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2GenDefinition(1000));
    $generationId = (int) p6a2Start($segmentId, 1)->generation_id;

    p6a2Batch($generationId);
    $cursorBefore = (int) p6a2Generation($generationId)->cursor_contact_id;
    $membersBefore = (int) p6a2Generation($generationId)->members_count;

    // Force the next batch to fail atomically, by the table's own owner.
    Fx::owner()->statement('SET ROLE digitrove_crm_executor');
    try {
        Fx::owner()->statement('ALTER TABLE public.crm_segment_generation_members ADD CONSTRAINT p6a2_force_failure CHECK (contact_id < 0) NOT VALID');
        expect(p6a2Batch($generationId)->status)->toBe('failed');
    } finally {
        Fx::owner()->statement('ALTER TABLE public.crm_segment_generation_members DROP CONSTRAINT IF EXISTS p6a2_force_failure');
        Fx::owner()->statement('RESET ROLE');
    }

    $failed = p6a2Generation($generationId);
    expect($failed->status)->toBe('failed')
        ->and((int) $failed->cursor_contact_id)->toBe($cursorBefore)
        ->and((int) $failed->members_count)->toBe($membersBefore)
        ->and($failed->last_error_code)->not->toBeNull();

    // A failed generation never resumes on its own.
    expect(p6a2Batch($generationId)->status)->toBe('failed');

    expect(Fx::owner()->selectOne('SELECT * FROM retry_crm_segment_generation(?)', [$generationId])->status)->toBe('ready');

    while (p6a2Batch($generationId)->status === 'ready') {
    }
    expect(p6a2Generation($generationId)->status)->toBe('published')
        ->and((int) p6a2Generation($generationId)->members_count)->toBe(3);
});

it('lists due generations and nothing once published', function () {
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2GenDefinition());
    $generationId = (int) p6a2Start($segmentId)->generation_id;

    $due = Fx::owner()->select('SELECT * FROM list_due_crm_segment_generations(50)');
    expect(collect($due)->pluck('generation_id')->map(fn ($v) => (int) $v)->all())->toContain($generationId);

    while (p6a2Batch($generationId)->status === 'ready') {
    }

    $dueAfter = Fx::owner()->select('SELECT * FROM list_due_crm_segment_generations(50)');
    expect(collect($dueAfter)->pluck('generation_id')->map(fn ($v) => (int) $v)->all())->not->toContain($generationId);
});
