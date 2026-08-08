<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use PDO;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-A2 concurrency: version numbering, single active generation, batch serialisation,
 * cross-segment independence and the version-vs-generation race.
 */
function p6a2SecondConnection(): PDO
{
    $cfg = config('database.connections.pgsql_migration');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'] ?? 5432, Fx::owner()->getDatabaseName()),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function p6a2ConcDefinition(int $value = 1): array
{
    return Fx::definition([
        'field' => 'commerce.net_revenue_minor',
        'operator' => 'gte',
        'currency' => 'XOF',
        'value' => $value,
    ]);
}

it('serialises concurrent version creation on the segment row so numbers stay unique', function () {
    $segmentId = (int) Fx::owner()->selectOne('SELECT * FROM create_crm_segment(?)', ['Concurrent'])->segment_id;
    $definition = json_encode(p6a2ConcDefinition(), JSON_THROW_ON_ERROR);

    $connA = Fx::owner();
    $connB = p6a2SecondConnection();

    // A holds the segment row exactly as create_crm_segment_version does.
    $connA->beginTransaction();
    $connA->selectOne('SELECT 1 AS locked FROM crm_segments WHERE id = ? FOR UPDATE', [$segmentId]);

    $connB->exec('SET statement_timeout TO 800');
    $blocked = false;
    try {
        $stmt = $connB->prepare('SELECT * FROM create_crm_segment_version(?, ?::jsonb)');
        $stmt->execute([$segmentId, $definition]);
    } catch (PDOException) {
        $blocked = true;
    }
    expect($blocked)->toBeTrue();

    $connA->selectOne('SELECT * FROM create_crm_segment_version(?, ?::jsonb)', [$segmentId, $definition]);
    $connA->commit();

    // B proceeds afterwards and gets the NEXT number, never a duplicate.
    $connB->exec('SET statement_timeout TO 0');
    $stmt = $connB->prepare('SELECT * FROM create_crm_segment_version(?, ?::jsonb)');
    $stmt->execute([$segmentId, $definition]);
    $second = $stmt->fetch(PDO::FETCH_ASSOC);
    $connB = null;

    expect((int) $second['version_number'])->toBe(2);
    expect((int) Fx::owner()->selectOne('SELECT COUNT(DISTINCT version_number) AS c FROM crm_segment_versions WHERE segment_id = ?', [$segmentId])->c)->toBe(2);
});

it('allows only one active generation per segment under concurrency', function () {
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2ConcDefinition());
    Fx::owner()->selectOne('SELECT * FROM start_crm_segment_generation(?, ?)', [$segmentId, 10]);

    $connB = p6a2SecondConnection();
    $refused = false;
    try {
        $connB->query('SELECT * FROM start_crm_segment_generation('.$segmentId.', 10)')->fetchAll();
    } catch (PDOException) {
        $refused = true;
    }
    $connB = null;

    expect($refused)->toBeTrue()
        ->and((int) Fx::owner()->selectOne('SELECT COUNT(*) AS c FROM crm_segment_generations WHERE segment_id = ?', [$segmentId])->c)->toBe(1);
});

it('serialises two workers on the same generation', function () {
    foreach (range(1, 3) as $ignored) {
        $contactId = Fx::contact();
        Fx::rollup($contactId, 'XOF', gross: 5000);
    }
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2ConcDefinition());
    $generationId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_segment_generation(?, ?)', [$segmentId, 1])->generation_id;

    $connA = Fx::owner();
    $connB = p6a2SecondConnection();

    $connA->beginTransaction();
    $connA->selectOne('SELECT 1 AS locked FROM crm_segment_generations WHERE id = ? FOR UPDATE', [$generationId]);

    $connB->exec('SET statement_timeout TO 800');
    $blocked = false;
    try {
        $connB->query('SELECT * FROM process_crm_segment_generation_batch('.$generationId.')')->fetchAll();
    } catch (PDOException) {
        $blocked = true;
    }
    expect($blocked)->toBeTrue();

    $connA->commit();

    $connB->exec('SET statement_timeout TO 0');
    $connB->query('SELECT * FROM process_crm_segment_generation_batch('.$generationId.')')->fetchAll();
    $connB = null;

    // Exactly one contact was consumed by the batch that actually ran.
    expect((int) Fx::owner()->selectOne('SELECT members_count AS c FROM crm_segment_generations WHERE id = ?', [$generationId])->c)->toBe(1);
});

it('lets two different segments build independently', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 5000);

    ['segment_id' => $a] = Fx::createSegmentWithVersion(p6a2ConcDefinition());
    ['segment_id' => $b] = Fx::createSegmentWithVersion(p6a2ConcDefinition());

    Fx::buildGeneration($a);
    Fx::buildGeneration($b);

    expect(Fx::currentMembers($a))->toBe([$contactId])
        ->and(Fx::currentMembers($b))->toBe([$contactId]);
});

it('pins the version for the whole build: a new version cannot be published mid-generation', function () {
    $contactId = Fx::contact();
    Fx::rollup($contactId, 'XOF', gross: 5000);
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2ConcDefinition(1));

    $generationId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_segment_generation(?, ?)', [$segmentId, 1])->generation_id;

    // A stricter v2 is drafted while v1 is materialising.
    $v2 = (int) Fx::owner()->selectOne(
        'SELECT * FROM create_crm_segment_version(?, ?::jsonb)',
        [$segmentId, json_encode(p6a2ConcDefinition(999999), JSON_THROW_ON_ERROR)],
    )->version_id;

    expect(fn () => Fx::owner()->selectOne('SELECT * FROM publish_crm_segment_version(?)', [$v2]))
        ->toThrow(QueryException::class);

    // The generation therefore completes against the version it started with.
    while (Fx::owner()->selectOne('SELECT * FROM process_crm_segment_generation_batch(?)', [$generationId])->status === 'ready') {
    }
    expect(Fx::currentMembers($segmentId))->toBe([$contactId]);

    // Once the build is over, v2 publishes normally.
    expect(Fx::owner()->selectOne('SELECT * FROM publish_crm_segment_version(?)', [$v2])->status)->toBe('published');
});

it('documents the build window: facts may change mid-build, publication stays atomic', function () {
    $stable = Fx::contact();
    Fx::rollup($stable, 'XOF', gross: 50000);
    $mutating = Fx::contact();
    Fx::rollup($mutating, 'XOF', gross: 50000);

    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(p6a2ConcDefinition(1000));
    $generationId = (int) Fx::owner()->selectOne('SELECT * FROM start_crm_segment_generation(?, ?)', [$segmentId, 1])->generation_id;

    // First batch scans the first contact and matches it.
    Fx::owner()->selectOne('SELECT * FROM process_crm_segment_generation_batch(?)', [$generationId]);

    // The second contact's rollup collapses mid-build: a generation is a build WINDOW,
    // not an MVCC snapshot of the facts.
    Fx::owner()->update(
        "UPDATE crm_contact_commerce_rollups SET gross_revenue_minor = 1, refunded_amount_minor = 0, refreshed_at = NOW() WHERE contact_id = ? AND currency = 'XOF'",
        [$mutating],
    );

    while (Fx::owner()->selectOne('SELECT * FROM process_crm_segment_generation_batch(?)', [$generationId])->status === 'ready') {
    }

    // The membership published is coherent and atomic, containing only the first.
    expect(Fx::currentMembers($segmentId))->toBe([$stable]);
});
