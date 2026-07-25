<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

it('creates one bounded monthly partition idempotently without moving default rows', function () {
    $worker = DB::connection('pgsql_analytics_worker');
    $owner = DB::connection('pgsql_migration');
    $month = CarbonImmutable::now('UTC')->startOfMonth()->addMonths(7);
    $name = 'events_y'.$month->format('Y').'m'.$month->format('m');

    $first = json_decode(
        $worker->selectOne(
            'SELECT public.ensure_analytics_events_month_partition(?::date)::text AS result',
            [$month->toDateString()],
        )->result,
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $second = json_decode(
        $worker->selectOne(
            'SELECT public.ensure_analytics_events_month_partition(?::date)::text AS result',
            [$month->toDateString()],
        )->result,
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($first['partition'])->toBe($name)
        ->and($first['created'])->toBeTrue()
        ->and($second['partition'])->toBe($name)
        ->and($second['created'])->toBeFalse()
        ->and($owner->selectOne(
            "SELECT count(*)::integer AS count FROM pg_inherits WHERE inhparent = 'public.events'::regclass AND inhrelid = ?::regclass",
            ['public.'.$name],
        )->count)->toBe(1)
        ->and($owner->selectOne(
            'SELECT has_table_privilege(\'digitrove_analytics_worker\', ?::regclass, \'SELECT\') AS allowed',
            ['public.'.$name],
        )->allowed)->toBeFalse();
});

it('refuses partition creation when the default partition already owns that month', function () {
    $worker = DB::connection('pgsql_analytics_worker');
    $owner = DB::connection('pgsql_migration');
    $month = CarbonImmutable::now('UTC')->startOfMonth()->addMonths(8);

    $owner->table('events')->insert([
        'public_id' => (string) Str::uuid(),
        'occurred_at' => $month->addDay()->toDateTimeString(),
        'event_name' => 'page_view',
        'properties' => '{}',
        'created_at' => $month->addDay()->toDateTimeString(),
    ]);

    $exception = null;

    try {
        $worker->selectOne(
            'SELECT public.ensure_analytics_events_month_partition(?::date)',
            [$month->toDateString()],
        );
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('23514')
        ->and($exception->getMessage())->toContain('default partition contains rows')
        ->and($owner->table('events_default')->whereBetween('occurred_at', [
            $month->toDateTimeString(),
            $month->addMonth()->toDateTimeString(),
        ])->count())->toBe(1);
});
