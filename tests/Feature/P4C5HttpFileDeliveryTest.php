<?php

declare(strict_types=1);

use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Services\Delivery\DownloadFileService;
use App\Services\Delivery\PrivateFileLocator;
use App\Support\DownloadAccessDenied;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

final class P4C5TransactionTrackingStream
{
    public $context;

    public static string $content = '';

    /** @var list<int> */
    public static array $levels = [];

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$levels[] = DB::transactionLevel();

        return true;
    }

    public function stream_read(int $count): string
    {
        self::$levels[] = DB::transactionLevel();
        $chunk = substr(self::$content, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$content);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen(self::$content) + $offset,
            default => -1,
        };

        if ($position < 0 || $position > strlen(self::$content)) {
            return false;
        }

        $this->position = $position;

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$content)];
    }
}

if (! in_array('p4c5tx', stream_get_wrappers(), true)) {
    stream_wrapper_register('p4c5tx', P4C5TransactionTrackingStream::class);
}

/**
 * @return array{grant: DownloadGrant, grant_token: string, attempt_token: string, content: string}
 */
function p4c5Attempt(string $seed = 'file'): array
{
    config([
        'app.env' => 'testing',
        'delivery.enabled' => true,
        'delivery.attempt.ttl_seconds' => 900,
        'delivery.log.retention_days' => 90,
        'delivery.audit.ip_hash_key' => str_repeat('k', 32),
        'delivery.audit.ip_hash_key_version' => 1,
        'delivery.rate_limit.authorize_per_minute' => 100,
        'delivery.rate_limit.file_per_minute' => 100,
        'delivery.private_disks' => ['private'],
        'delivery.file.driver' => 'stream',
        'delivery.file.stream_chunk_bytes' => 65_536,
        'delivery.acceleration.driver' => 'none',
        'delivery.acceleration.x_accel_prefix' => null,
        'delivery.acceleration.x_accel_min_bytes' => 0,
    ]);

    Storage::fake('private');
    $grantToken = rtrim(strtr(base64_encode(hash('sha256', $seed, true)), '+/', '-_'), '=');
    $grant = DownloadGrant::factory()->create([
        'token_hash' => hash('sha256', $grantToken),
        'max_downloads' => 3,
    ]);
    $file = $grant->productFile()->firstOrFail();
    $content = substr(str_repeat('0123456789', (int) ceil(((int) $file->size_bytes) / 10)), 0, (int) $file->size_bytes);
    Storage::disk('private')->put($file->storage_path, $content);

    $authorization = test()->withHeader('Authorization', 'Bearer '.$grantToken)
        ->postJson("/api/downloads/{$grant->public_id}/authorize")
        ->assertOk();

    return [
        'grant' => $grant,
        'grant_token' => $grantToken,
        'attempt_token' => $authorization->getCookie('dl_attempt')->getValue(),
        'content' => $content,
    ];
}

/**
 * @param  array<string, list<int>>  $levels
 */
function p4c5TrackPrivateDisk(array &$levels, ?Closure $readStream = null): void
{
    $backing = Storage::disk('private');
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->zeroOrMoreTimes()->andReturnUsing(
        function (string $path) use (&$levels, $backing): bool {
            $levels['exists'][] = DB::transactionLevel();

            return $backing->exists($path);
        },
    );
    $disk->shouldReceive('size')->zeroOrMoreTimes()->andReturnUsing(
        function (string $path) use (&$levels, $backing): int {
            $levels['size'][] = DB::transactionLevel();

            return $backing->size($path);
        },
    );
    $disk->shouldReceive('readStream')->zeroOrMoreTimes()->andReturnUsing(
        function (string $path) use (&$levels, $backing, $readStream) {
            $levels['readStream'][] = DB::transactionLevel();

            return $readStream === null ? $backing->readStream($path) : $readStream($path);
        },
    );

    Storage::set('private', $disk);
}

it('refuses missing, grant, foreign and query credentials without consuming again', function (): void {
    $attempt = p4c5Attempt('first');
    $foreign = p4c5Attempt('foreign');
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $responses = [
        $this->get($path),
        $this->withCookie('dl_attempt', $attempt['grant_token'])->get($path),
        $this->withCookie('dl_attempt', $foreign['attempt_token'])->get($path),
        $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path.'?token=forbidden'),
    ];

    foreach ($responses as $response) {
        $response->assertNotFound()
            ->assertSeeText('Download unavailable.');
        expect($response->headers->get('Cache-Control'))
            ->toContain('private')
            ->toContain('no-store');
    }

    expect(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->count())->toBe(1)
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('streams a complete private file with bounded security headers and one terminal log', function (): void {
    $attempt = p4c5Attempt();
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $response = $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path);

    $response->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Length', (string) strlen($attempt['content']));
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
        ->and($response->streamedContent())->toBe($attempt['content'])
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->all())->not->toContain(storage_path())
        ->and(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->sole()->status)->toBe('completed')
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('answers HEAD with the same metadata and no body, log, quota or status mutation', function (): void {
    $attempt = p4c5Attempt();
    $path = "/downloads/{$attempt['grant']->public_id}/file";
    $before = DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->sole();

    $response = $this->withCookie('dl_attempt', $attempt['attempt_token'])->head($path);

    $response->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Length', (string) strlen($attempt['content']));
    expect($response->getContent())->toBe('')
        ->and(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->count())->toBe(1)
        ->and(DownloadLog::query()->findOrFail($before->id)->status)->toBe('started')
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('reuses one completed attempt for retries without a second log or quota unit', function (): void {
    $attempt = p4c5Attempt();
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path)->assertOk();
    $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path)->assertOk();
    $this->withCookie('dl_attempt', $attempt['attempt_token'])->head($path)->assertOk();

    expect(DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->count())->toBe(1)
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('sanitizes the filename and fails closed on missing storage or incomplete acceleration', function (): void {
    $attempt = p4c5Attempt();
    DB::table('product_files')->where('id', $attempt['grant']->product_file_id)
        ->update(['original_name' => "report\r\nX-Injected: yes.zip"]);
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $safe = $this->withCookie('dl_attempt', $attempt['attempt_token'])->head($path);
    expect($safe->headers->get('Content-Disposition'))
        ->not->toContain("\r")
        ->not->toContain("\n")
        ->not->toContain('X-Injected:');

    $missing = p4c5Attempt('missing');
    Storage::disk('private')->delete($missing['grant']->productFile()->value('storage_path'));
    $this->withCookie('dl_attempt', $missing['attempt_token'])
        ->get("/downloads/{$missing['grant']->public_id}/file")
        ->assertNotFound()
        ->assertSeeText('Download unavailable.');
    expect(DownloadLog::query()->where('download_grant_id', $missing['grant']->id)->sole()->denial_reason_code)
        ->toBe('storage_failure');

    $accelerated = p4c5Attempt('accelerated');
    config(['delivery.file.driver' => 'x_accel', 'delivery.acceleration.driver' => 'x_accel']);
    $this->withCookie('dl_attempt', $accelerated['attempt_token'])
        ->get("/downloads/{$accelerated['grant']->public_id}/file")
        ->assertNotFound();
});

it('keeps file storage access and the HTTP stream callback outside database transactions', function (): void {
    $attempt = p4c5Attempt('storage-levels');
    $levels = ['exists' => [], 'size' => [], 'readStream' => []];
    P4C5TransactionTrackingStream::$content = $attempt['content'];
    P4C5TransactionTrackingStream::$levels = [];
    p4c5TrackPrivateDisk(
        $levels,
        fn () => fopen('p4c5tx://download', 'rb'),
    );

    $path = "/downloads/{$attempt['grant']->public_id}/file";
    $response = $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path);

    $response->assertOk();
    expect($response->streamedContent())->toBe($attempt['content'])
        ->and($levels['exists'])->not->toBeEmpty()
        ->and($levels['size'])->not->toBeEmpty()
        ->and($levels['readStream'])->not->toBeEmpty()
        ->and(max($levels['exists']))->toBe(0)
        ->and(max($levels['size']))->toBe(0)
        ->and(max($levels['readStream']))->toBe(0)
        ->and(P4C5TransactionTrackingStream::$levels)->not->toBeEmpty()
        ->and(max(P4C5TransactionTrackingStream::$levels))->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('keeps HEAD, Range and X-Accel resolution outside database transactions', function (): void {
    $attempt = p4c5Attempt('head-range-levels');
    $levels = ['exists' => [], 'size' => [], 'readStream' => []];
    p4c5TrackPrivateDisk($levels);
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $this->withCookie('dl_attempt', $attempt['attempt_token'])->head($path)->assertOk();
    $this->withCookie('dl_attempt', $attempt['attempt_token'])
        ->withHeader('Range', 'bytes=1-4')
        ->get($path)
        ->assertStatus(206);

    expect(max($levels['exists']))->toBe(0)
        ->and(max($levels['size']))->toBe(0)
        ->and(max($levels['readStream']))->toBe(0);

    $accelerated = p4c5Attempt('x-accel-level');
    $acceleratedLevels = ['exists' => [], 'size' => [], 'readStream' => []];
    p4c5TrackPrivateDisk($acceleratedLevels);
    config([
        'delivery.file.driver' => 'x_accel',
        'delivery.acceleration.driver' => 'x_accel',
        'delivery.acceleration.x_accel_prefix' => '/_digitrove_private',
        'delivery.acceleration.x_accel_min_bytes' => 0,
    ]);

    $response = $this->withoutHeader('Range')
        ->withCookie('dl_attempt', $accelerated['attempt_token'])
        ->get("/downloads/{$accelerated['grant']->public_id}/file");

    $response->assertOk()->assertHeader('X-Accel-Redirect');
    expect(max($acceleratedLevels['exists']))->toBe(0)
        ->and(max($acceleratedLevels['size']))->toBe(0)
        ->and($acceleratedLevels['readStream'])->toBe([])
        ->and(DB::transactionLevel())->toBe(0);
});

it('rejects ambient file preparation before database and storage access', function (): void {
    $attempt = p4c5Attempt('ambient-file');
    $levels = ['exists' => [], 'size' => [], 'readStream' => []];
    p4c5TrackPrivateDisk($levels);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $service = app(DownloadFileService::class);
    expect(fn () => DB::transaction(
        fn () => $service->prepare(
            (string) $attempt['grant']->public_id,
            $attempt['attempt_token'],
            null,
            false,
        ),
    ))->toThrow(RuntimeException::class, 'Download file preparation cannot join an ambient transaction.');

    expect($levels['exists'])->toBe([])
        ->and($levels['size'])->toBe([])
        ->and($levels['readStream'])->toBe([])
        ->and(collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'download_grants')
                || str_contains($sql, 'download_logs'),
        ))->toHaveCount(0);
});

it('uses the disabled pipeline as a complete file-delivery kill switch', function (): void {
    $attempt = p4c5Attempt('file-kill-switch');
    $levels = ['exists' => [], 'size' => [], 'readStream' => []];
    p4c5TrackPrivateDisk($levels);
    $log = DownloadLog::query()->where('download_grant_id', $attempt['grant']->id)->sole();
    config(['delivery.enabled' => false]);
    $path = "/downloads/{$attempt['grant']->public_id}/file";

    $this->withCookie('dl_attempt', $attempt['attempt_token'])->get($path)->assertNotFound();
    $this->withCookie('dl_attempt', $attempt['attempt_token'])->head($path)->assertNotFound();
    $this->withCookie('dl_attempt', $attempt['attempt_token'])
        ->withHeader('Range', 'bytes=0-2')
        ->get($path)
        ->assertNotFound();

    expect($levels['exists'])->toBe([])
        ->and($levels['size'])->toBe([])
        ->and($levels['readStream'])->toBe([])
        ->and(DownloadLog::query()->findOrFail($log->id)->status)->toBe('started')
        ->and(DownloadGrant::query()->findOrFail($attempt['grant']->id)->downloads_count)->toBe(1);
});

it('finalizes storage failures separately and closes streams rejected by final revalidation', function (): void {
    $missing = p4c5Attempt('separate-storage-failure');
    $missingLevels = ['exists' => [], 'size' => [], 'readStream' => []];
    $backing = Storage::disk('private');
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->once()->andReturnUsing(
        function () use (&$missingLevels): bool {
            $missingLevels['exists'][] = DB::transactionLevel();

            return false;
        },
    );
    Storage::set('private', $disk);

    $this->withCookie('dl_attempt', $missing['attempt_token'])
        ->get("/downloads/{$missing['grant']->public_id}/file")
        ->assertNotFound();
    $missingLog = DownloadLog::query()->where('download_grant_id', $missing['grant']->id)->sole();
    expect($missingLevels['exists'])->toBe([0])
        ->and($missingLog->status)->toBe('denied')
        ->and($missingLog->denial_reason_code)->toBe('storage_failure')
        ->and(DB::transactionLevel())->toBe(0);

    Storage::set('private', $backing);
    $raced = p4c5Attempt('final-revalidation');
    $racedLevels = ['exists' => [], 'size' => [], 'readStream' => []];
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $raced['content']);
    rewind($stream);
    p4c5TrackPrivateDisk(
        $racedLevels,
        function () use ($raced, $stream) {
            DB::table('download_grants')->where('id', $raced['grant']->id)->update([
                'revoked_at' => now(),
                'revoked_reason_code' => 'manual_security_reissue',
            ]);

            return $stream;
        },
    );

    expect(fn () => app(DownloadFileService::class)->prepare(
        (string) $raced['grant']->public_id,
        $raced['attempt_token'],
        null,
        false,
    ))->toThrow(DownloadAccessDenied::class);

    expect(is_resource($stream))->toBeFalse()
        ->and(max($racedLevels['readStream']))->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('guards every private file locator entry point against ambient transactions', function (): void {
    $attempt = p4c5Attempt('locator-guard');
    $file = $attempt['grant']->productFile()->firstOrFail();
    $locator = app(PrivateFileLocator::class);
    config([
        'delivery.acceleration.driver' => 'x_accel',
        'delivery.acceleration.x_accel_prefix' => '/_digitrove_private',
    ]);

    $calls = [
        fn () => $locator->diskFor($file),
        fn () => $locator->assertResolvable($file),
        fn () => $locator->size($file),
        fn () => $locator->readStream($file),
        fn () => $locator->xAccelPath($file),
    ];

    foreach ($calls as $call) {
        expect(fn () => DB::transaction($call))
            ->toThrow(RuntimeException::class, 'Private file access must run outside a database transaction.');
    }
});
