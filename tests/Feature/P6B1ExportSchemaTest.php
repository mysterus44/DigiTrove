<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

function p6b1Owner()
{
    return Fx::owner();
}

function p6b1Admin(): int
{
    return (int) \Tests\Support\CrmAdminFixtures::admin()->id;
}

it('creates exactly migration 000027 and no 000028', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(43)
        ->and(glob($root.'/database/migrations/2026_07_14_000027*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000028*.php') ?: [])->toBe([]);
});

it('creates crm_exports owned by the CRM executor with no runtime table access', function () {
    $owner = p6b1Owner()->selectOne(<<<'SQL'
        SELECT pg_get_userbyid(relowner) AS owner
        FROM pg_class WHERE relname = 'crm_exports' AND relkind = 'r'
        SQL);

    expect($owner?->owner)->toBe('digitrove_crm_executor');

    // The runtime holds NO privilege at all on the table: its only way in is EXECUTE.
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
        $granted = p6b1Owner()->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS granted',
            ['digitrove_runtime', 'public.crm_exports', $privilege],
        );

        expect((bool) $granted->granted)->toBeFalse("runtime must not hold {$privilege} on crm_exports");
    }

    foreach (['SELECT', 'INSERT'] as $privilege) {
        $granted = p6b1Owner()->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS granted',
            ['public', 'public.crm_exports', $privilege],
        );

        expect((bool) $granted->granted)->toBeFalse("PUBLIC must not hold {$privilege} on crm_exports");
    }
});

it('grants the runtime EXECUTE on every export authority and nothing else', function () {
    $authorities = [
        'create_crm_export', 'claim_crm_export', 'complete_crm_export', 'fail_crm_export',
        'get_crm_export', 'list_crm_exports', 'list_due_crm_exports', 'expire_crm_exports',
        'list_crm_export_contact_rows', 'list_crm_export_member_rows',
    ];

    foreach ($authorities as $authority) {
        $row = p6b1Owner()->selectOne(<<<'SQL'
            SELECT p.oid::regprocedure AS signature,
                   pg_get_userbyid(p.proowner) AS owner,
                   p.prosecdef,
                   has_function_privilege('digitrove_runtime', p.oid, 'EXECUTE') AS runtime_execute,
                   has_function_privilege('public', p.oid, 'EXECUTE') AS public_execute
            FROM pg_proc AS p
            JOIN pg_namespace AS n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname = ?
            SQL, [$authority]);

        expect($row)->not->toBeNull("authority {$authority} must exist")
            ->and($row->owner)->toBe('digitrove_crm_executor')
            ->and((bool) $row->prosecdef)->toBeTrue("{$authority} must be SECURITY DEFINER")
            ->and((bool) $row->runtime_execute)->toBeTrue()
            ->and((bool) $row->public_execute)->toBeFalse("PUBLIC must not execute {$authority}");
    }
});

it('keeps every read authority STABLE so none of them can mutate', function () {
    foreach ([
        'get_crm_export', 'list_crm_exports', 'list_due_crm_exports',
        'list_crm_export_contact_rows', 'list_crm_export_member_rows',
    ] as $authority) {
        expect((string) p6b1Owner()->selectOne(
            'SELECT provolatile FROM pg_proc WHERE proname = ?', [$authority],
        )->provolatile)->toBe('s', "{$authority} must be STABLE");
    }
});

it('pins a fixed search_path on every authority', function () {
    $rows = p6b1Owner()->select(<<<'SQL'
        SELECT p.proname, p.proconfig
        FROM pg_proc AS p
        JOIN pg_namespace AS n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.proname LIKE '%crm_export%'
        SQL);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(implode(',', (array) json_decode(json_encode($row->proconfig) ?: '[]') ?: []))
            ->toContain('search_path=pg_catalog, public, pg_temp');
    }
});

it('binds a member export to a segment and generation, and a contact export to neither', function () {
    $adminId = p6b1Admin();
    ['segment_id' => $segmentId] = Fx::createSegmentWithVersion(
        Fx::definition(['field' => 'contact.status', 'operator' => 'in', 'values' => ['active']]),
    );
    Fx::buildGeneration($segmentId);

    // A contact export carries no segment scope at all.
    $contactExport = p6b1Owner()->selectOne(
        'SELECT * FROM create_crm_export(?, ?, NULL, ?, ?)',
        ['crm_contacts', $adminId, 100, 24],
    );
    expect($contactExport->generation_id)->toBeNull();

    // A member export freezes the current generation at creation.
    $memberExport = p6b1Owner()->selectOne(
        'SELECT * FROM create_crm_export(?, ?, ?, ?, ?)',
        ['segment_current_members', $adminId, $segmentId, 100, 24],
    );
    expect((int) $memberExport->generation_id)->toBeGreaterThan(0);

    // Passing a segment to a contact export is refused outright.
    expect(fn () => p6b1Owner()->selectOne(
        'SELECT * FROM create_crm_export(?, ?, ?, ?, ?)',
        ['crm_contacts', $adminId, $segmentId, 100, 24],
    ))->toThrow(QueryException::class);
});

it('refuses an unknown kind, an out-of-range row limit or TTL', function () {
    $adminId = p6b1Admin();

    foreach ([
        ['xlsx_dump', null, 100, 24],
        ['crm_contacts', null, 0, 24],
        ['crm_contacts', null, 50001, 24],
        ['crm_contacts', null, 100, 0],
        ['crm_contacts', null, 100, 169],
    ] as [$kind, $segmentId, $rowLimit, $ttl]) {
        expect(fn () => p6b1Owner()->selectOne(
            'SELECT * FROM create_crm_export(?, ?, ?, ?, ?)',
            [$kind, $adminId, $segmentId, $rowLimit, $ttl],
        ))->toThrow(QueryException::class);
    }
});

it('refuses a member export for a segment with no published generation', function () {
    $adminId = p6b1Admin();
    $segmentId = (int) p6b1Owner()->selectOne('SELECT * FROM create_crm_segment(?)', ['Jamais construit'])->segment_id;

    // An empty file would read as "this segment has no members", which is a different
    // and false statement. Refusing is the honest outcome.
    expect(fn () => p6b1Owner()->selectOne(
        'SELECT * FROM create_crm_export(?, ?, ?, ?, ?)',
        ['segment_current_members', $adminId, $segmentId, 100, 24],
    ))->toThrow(QueryException::class);
});

it('enforces the completed payload contract at the database level', function () {
    $adminId = p6b1Admin();
    $exportId = (int) p6b1Owner()->selectOne(
        'SELECT * FROM create_crm_export(?, ?, NULL, ?, ?)',
        ['crm_contacts', $adminId, 100, 24],
    )->export_id;

    // A "completed" row without its artefact description is structurally impossible.
    expect(fn () => p6b1Owner()->update(
        "UPDATE crm_exports SET status = 'completed', completed_at = NOW() WHERE id = ?", [$exportId],
    ))->toThrow(QueryException::class);

    expect(fn () => p6b1Owner()->update(
        "UPDATE crm_exports SET status = 'failed' WHERE id = ?", [$exportId],
    ))->toThrow(QueryException::class);
});

/**
 * Asserted HERE rather than in the generation test, where `Storage::fake()` replaces the
 * disk configuration and would silently make this assertion measure the fake instead of
 * the real one.
 */
it('resolves an export disk that is local and not reachable over HTTP', function () {
    $disk = App\Support\CrmConfig::exportDisk();
    $configured = config("filesystems.disks.{$disk}");

    expect($disk)->toBe('private')
        ->and($configured['driver'])->toBe('local')
        ->and($configured['visibility'])->toBe('private')
        // No `url` key: nothing written here is addressable over HTTP.
        ->and($configured)->not->toHaveKey('url');

    // The public disk can never be chosen, whatever the env says.
    config(['crm.exports.disk' => 'public']);
    expect(fn () => App\Support\CrmConfig::exportDisk())->toThrow(RuntimeException::class);

    config(['crm.exports.disk' => 's3']);
    expect(fn () => App\Support\CrmConfig::exportDisk())->toThrow(RuntimeException::class);

    config(['crm.exports.disk' => '../../etc']);
    expect(fn () => App\Support\CrmConfig::exportDisk())->toThrow(RuntimeException::class);
});

it('constrains the checksum, error code and terminal reason shapes', function () {
    $adminId = p6b1Admin();
    $exportId = (int) p6b1Owner()->selectOne(
        'SELECT * FROM create_crm_export(?, ?, NULL, ?, ?)',
        ['crm_contacts', $adminId, 100, 24],
    )->export_id;

    expect(fn () => p6b1Owner()->update(
        'UPDATE crm_exports SET checksum_sha256 = ? WHERE id = ?', ['NOTAHEX', $exportId],
    ))->toThrow(QueryException::class);

    expect(fn () => p6b1Owner()->update(
        'UPDATE crm_exports SET last_error_code = ? WHERE id = ?', ['boom', $exportId],
    ))->toThrow(QueryException::class);

    expect(fn () => p6b1Owner()->update(
        'UPDATE crm_exports SET terminal_reason = ? WHERE id = ?', ['because', $exportId],
    ))->toThrow(QueryException::class);
});
