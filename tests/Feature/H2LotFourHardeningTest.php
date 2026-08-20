<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| Durcissement lot 4 — H2.6 CORS, H2.7 failed_jobs, H2.8 canal de log
|--------------------------------------------------------------------------
|
| Real PostgreSQL under `digitrove_runtime`. The failed_jobs assertions matter
| most under that role: a table the worker cannot write to would be exactly the
| silent failure this lot exists to remove.
|
*/

/*
|--------------------------------------------------------------------------
| H2.6 — CORS
|--------------------------------------------------------------------------
*/

it('allows no third-party origin on any api route', function (string $uri): void {
    // MEASURED, not deduced: without config/cors.php the framework defaults applied
    // (`allowed_origins => ['*']`) and that permission was visible nowhere in the repository.
    $response = $this->call('OPTIONS', $uri, [], [], [], [
        'HTTP_ORIGIN' => 'https://evil.test',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull()
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBeNull();
})->with([
    '/api/webhooks/payments/cinetpay',
    '/api/webhooks/payments/geniuspay',
    '/api/downloads/abc-123/authorize',
]);

it('never turns on credentials, which is what would make a nominative list dangerous', function (): void {
    // `*` plus credentials is refused by browsers outright; a nominative list PLUS
    // credentials is precisely what lets a third-party site ride a session.
    expect(config('cors.supports_credentials'))->toBeFalse()
        ->and(config('cors.allowed_origins'))->toBe([])
        // A pattern is far easier to widen by accident than a list of names.
        ->and(config('cors.allowed_origins_patterns'))->toBe([]);
});

it('still serves same-origin api calls, which is the only kind the storefront makes', function (): void {
    // `resources/views/downloads/exchange.blade.php` and `carts/resume.blade.php` both use
    // RELATIVE paths, so CORS never applies to them. Refusing every origin must not have
    // touched them.
    $this->postJson('/api/webhooks/payments/geniuspay', [])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| H2.7 — failed_jobs
|--------------------------------------------------------------------------
*/

it('creates failed_jobs with the standard shape', function (): void {
    expect(Schema::hasTable('failed_jobs'))->toBeTrue();

    $columns = collect(DB::connection('pgsql_migration')->select(<<<'SQL'
        SELECT attname FROM pg_attribute
        WHERE attrelid = 'public.failed_jobs'::regclass AND attnum > 0 AND NOT attisdropped
        SQL))->pluck('attname')->sort()->values()->all();

    expect($columns)->toBe(['connection', 'exception', 'failed_at', 'id', 'payload', 'queue', 'uuid']);
});

it('lets the RUNTIME role write a failure, which is the whole point', function (): void {
    // ⚠️ The assertion that matters. `000012` grants table privileges through
    // ALTER DEFAULT PRIVILEGES, so this should be inherited — but a table the worker cannot
    // write to would be a MUTE failure log, exactly the silence this lot removes. Proven
    // under `digitrove_runtime`, not assumed from the migration.
    expect(DB::selectOne('SELECT current_user AS role')->role)->toBe('digitrove_runtime');

    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{"displayName":"App\\\\Jobs\\\\SecureDeliveryJob"}',
        'exception' => 'RuntimeException: the worker died',
        'failed_at' => now(),
    ]);

    $row = DB::table('failed_jobs')->where('uuid', $uuid)->sole();

    expect($row->connection)->toBe('redis')
        // And it can be read back and cleared, which `queue:retry` and `queue:forget` need.
        ->and(DB::table('failed_jobs')->where('uuid', $uuid)->delete())->toBe(1);
});

it('keeps the uuid unique, because that is how a failure is retrieved', function (): void {
    $uuid = (string) Str::uuid();
    $row = [
        'uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
    ];

    DB::table('failed_jobs')->insert($row);

    expect(fn () => DB::table('failed_jobs')->insert($row))
        ->toThrow(QueryException::class);
});

it('points the failed driver at the database, not at a file nobody reads', function (): void {
    // The defect: config/queue.php named `failed_jobs` while the table never existed, and
    // .env.example worked around it with the `file` driver.
    expect(config('queue.failed.table'))->toBe('failed_jobs')
        ->and(file_get_contents(base_path('.env.example')))
        ->toMatch('/(?m)^QUEUE_FAILED_DRIVER=database-uuids$/')
        ->not->toMatch('/(?m)^QUEUE_FAILED_DRIVER=file$/');
});

it('never defaults a queue connection to sqlite, which this project forbids', function (): void {
    expect(file_get_contents(base_path('config/queue.php')))
        ->not->toContain("env('DB_CONNECTION', 'sqlite')");
});

/*
|--------------------------------------------------------------------------
| H2.8 — un `critical` doit pouvoir être vu
|--------------------------------------------------------------------------
*/

it('ships a rotating log channel, the activation prerequisite for H1', function (): void {
    // H1 raises Log::critical. With `single`, everything piles into one unbounded file: the
    // alert is present but unfindable, and the file eventually cannot be opened at all.
    // WEBHOOK_RECONCILIATION_ENABLED must not go true before this is in place.
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/(?m)^LOG_STACK=daily$/')
        ->not->toMatch('/(?m)^LOG_STACK=single$/')
        ->and(config('logging.channels.daily.days'))->toBeGreaterThanOrEqual(14);
});

it('does not ship debug as the template log level', function (): void {
    // `debug` in production drowns alerts in noise. A dev lowers it in their own .env.
    expect(file_get_contents(base_path('.env.example')))
        ->toMatch('/(?m)^LOG_LEVEL=info$/')
        ->not->toMatch('/(?m)^LOG_LEVEL=debug$/');
});

it('keeps the reconciliation disabled by default until the channel is usable', function (): void {
    expect(file_get_contents(base_path('.env.example')))
        ->toMatch('/(?m)^WEBHOOK_RECONCILIATION_ENABLED=false$/');
});

/*
|--------------------------------------------------------------------------
| Frontière de migration
|--------------------------------------------------------------------------
*/

it('counts 53 migrations, names the last one, and has no 000038', function (): void {
    $files = collect(File::files(database_path('migrations')))
        ->map(fn ($file): string => $file->getFilename())
        ->sort()
        ->values()
        ->all();

    expect($files)->toHaveCount(53)
        ->and(end($files))->toBe('2026_07_14_000037_create_failed_jobs_table.php')
        ->and(collect($files)->filter(fn (string $f): bool => str_contains($f, '000038')))->toBeEmpty();
});
