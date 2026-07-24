<?php

declare(strict_types=1);

use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Models\ProductFile;
use App\Services\Delivery\DownloadAuthorizationService;
use App\Services\Delivery\GrantIssuanceService;
use App\Support\DownloadAccessDenied;
use App\Support\DownloadRequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| P4-C4/C5/C6 — Real PostgreSQL concurrency proofs (D-036)
|--------------------------------------------------------------------------
|
| Child processes boot the real Laravel application and use the restricted
| runtime role. Raw credentials travel through an anonymous stdin pipe only:
| never argv, environment variables, database rows, exceptions or logs.
|
*/

function p4c456RuntimePdo(): PDO
{
    $connection = config('database.connections.pgsql');

    return new PDO(
        sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $connection['host'],
            $connection['port'] ?? 5432,
            $connection['database'],
        ),
        $connection['username'],
        $connection['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function p4c456Process(string $operation, array $payload): Process
{
    $script = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        config([
            'filesystems.disks.private' => [
                'driver' => 'local',
                'root' => $payload['storage_root'],
                'visibility' => 'private',
                'serve' => false,
                'throw' => true,
            ],
            'delivery.private_disks' => ['private'],
            'delivery.file.driver' => 'stream',
            'delivery.file.stream_chunk_bytes' => 65536,
            'delivery.acceleration.driver' => 'none',
            'delivery.attempt.ttl_seconds' => 900,
            'delivery.log.retention_days' => 90,
            'delivery.operations.started_reconcile_minutes' => 30,
            'delivery.operations.abuse_window_hours' => 24,
            'delivery.operations.abuse_distinct_ip_threshold' => 3,
            'delivery.operations.batch_size' => 500,
        ]);
        Illuminate\Support\Facades\DB::statement("SET lock_timeout = '10s'");

        try {
            $operation = $argv[1];

            if ($operation === 'authorize') {
                app(App\Services\Delivery\DownloadAuthorizationService::class)->authorize(
                    $payload['grant_public_id'],
                    $payload['grant_token'],
                    new App\Support\DownloadRequestContext(
                        str_repeat('a', 64),
                        1,
                        'P4C456 concurrency',
                    ),
                );
                fwrite(STDOUT, 'authorized');
            } elseif ($operation === 'file') {
                $prepared = app(App\Services\Delivery\DownloadFileService::class)->prepare(
                    $payload['grant_public_id'],
                    $payload['attempt_token'],
                    $payload['range'] ?? null,
                    false,
                );
                if (is_resource($prepared->stream)) {
                    fclose($prepared->stream);
                }
                fwrite(STDOUT, 'prepared');
            } elseif ($operation === 'revoke') {
                $result = app(App\Services\Delivery\DownloadOperationsService::class)->revokeGrant(
                    (int) $payload['grant_id'],
                    'manual_security_reissue',
                );
                fwrite(STDOUT, $result ? 'revoked' : 'refused');
            } elseif ($operation === 'reconcile') {
                fwrite(
                    STDOUT,
                    (string) app(App\Services\Delivery\DownloadOperationsService::class)->reconcileStarted(),
                );
            } elseif ($operation === 'purge') {
                fwrite(
                    STDOUT,
                    (string) app(App\Services\Delivery\DownloadOperationsService::class)->purgeLogs(),
                );
            } else {
                throw new RuntimeException('Unknown P4-C4/C6 concurrency operation.');
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception::class.':'.$exception->getMessage());
            exit(2);
        }
        PHP;

    $process = new Process(
        [PHP_BINARY, '-r', $script, $operation],
        base_path(),
        ['APP_ENV' => 'testing'],
        null,
        60,
    );
    $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));

    return $process;
}

/**
 * @return array{
 *     order_id:int,
 *     grant:DownloadGrant,
 *     grant_public_id:string,
 *     grant_token:string,
 *     storage_root:string
 * }
 */
function p4c456DeliverableGrant(int $maxDownloads = 5): array
{
    $storageRoot = storage_path('framework/testing/p4c456/'.strtolower(Str::random(12)));
    $storagePath = 'products/'.strtolower(Str::random(12)).'/payload.bin';
    $content = str_repeat('0123456789abcdef', 512);
    $absolutePath = $storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $storagePath);

    File::ensureDirectoryExists(dirname($absolutePath));
    File::put($absolutePath, $content);

    config([
        'filesystems.disks.private' => [
            'driver' => 'local',
            'root' => $storageRoot,
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
        ],
        'delivery.private_disks' => ['private'],
        'delivery.grant.max_downloads' => $maxDownloads,
        'delivery.attempt.ttl_seconds' => 900,
        'delivery.log.retention_days' => 90,
        'delivery.file.driver' => 'stream',
        'delivery.file.stream_chunk_bytes' => 65_536,
        'delivery.acceleration.driver' => 'none',
        'delivery.operations.started_reconcile_minutes' => 30,
        'delivery.operations.abuse_window_hours' => 24,
        'delivery.operations.abuse_distinct_ip_threshold' => 3,
        'delivery.operations.batch_size' => 500,
    ]);

    $product = p4cProduct();
    DB::transaction(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'storage_disk' => 'private',
        'storage_path' => $storagePath,
        'original_name' => 'payload.bin',
        'mime_type' => 'application/octet-stream',
        'size_bytes' => strlen($content),
        'checksum_sha256' => hash('sha256', $content),
        'is_active' => true,
    ]));
    ['order' => $order] = p4cDeliverableOrder($product);
    $issued = (new GrantIssuanceService)->issueForOrder($order->id)->grants[0];

    return [
        'order_id' => (int) $order->id,
        'grant' => DownloadGrant::query()->findOrFail($issued->grantId),
        'grant_public_id' => $issued->grantPublicId,
        'grant_token' => $issued->rawToken,
        'storage_root' => $storageRoot,
    ];
}

/**
 * @param  list<Process>  $processes
 */
function p4c456ReleaseOrderLockAfterProcessesStart(int $orderId, array $processes): void
{
    $locker = p4c456RuntimePdo();

    try {
        $locker->beginTransaction();
        $statement = $locker->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
        $statement->execute([$orderId]);

        foreach ($processes as $process) {
            $process->start();
        }

        usleep(500_000);
        foreach ($processes as $process) {
            expect($process->isRunning())->toBeTrue('A worker did not wait behind the Order lock.');
        }

        $locker->commit();
        foreach ($processes as $process) {
            $process->wait();
        }
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        $locker = null;
    }
}

function p4c456Payload(array $fixture): array
{
    return [
        'grant_id' => $fixture['grant']->id,
        'grant_public_id' => $fixture['grant_public_id'],
        'grant_token' => $fixture['grant_token'],
        'storage_root' => $fixture['storage_root'],
    ];
}

beforeEach(function (): void {
    p4cConfig();
});

afterEach(function (): void {
    $roots = glob(storage_path('framework/testing/p4c456/*'), GLOB_ONLYDIR) ?: [];
    foreach ($roots as $root) {
        File::deleteDirectory($root);
    }
});

it('C1 serialises simultaneous authorization and never consumes twice', function (): void {
    $fixture = p4c456DeliverableGrant();
    $payload = p4c456Payload($fixture);
    $first = p4c456Process('authorize', $payload);
    $second = p4c456Process('authorize', $payload);

    p4c456ReleaseOrderLockAfterProcessesStart($fixture['order_id'], [$first, $second]);

    $codes = [$first->getExitCode(), $second->getExitCode()];
    sort($codes);

    expect($codes)->toBe([0, 2])
        ->and(DownloadLog::query()->where('download_grant_id', $fixture['grant']->id)->count())->toBe(1)
        ->and($fixture['grant']->refresh()->downloads_count)->toBe(1)
        ->and($first->getErrorOutput().$second->getErrorOutput())
        ->not->toContain('25P02')
        ->not->toContain('42501')
        ->not->toContain($fixture['grant_token']);
});

it('C2 serialises authorization against revocation without issuing after a winning revocation', function (): void {
    $fixture = p4c456DeliverableGrant();
    $payload = p4c456Payload($fixture);
    $authorization = p4c456Process('authorize', $payload);
    $revocation = p4c456Process('revoke', $payload);

    p4c456ReleaseOrderLockAfterProcessesStart($fixture['order_id'], [$authorization, $revocation]);

    expect($revocation->isSuccessful())->toBeTrue($revocation->getErrorOutput())
        ->and($revocation->getOutput())->toBe('revoked')
        ->and($authorization->getExitCode())->toBeIn([0, 2])
        ->and($fixture['grant']->refresh()->revoked_reason_code)->toBe('manual_security_reissue')
        ->and(DownloadLog::query()
            ->where('download_grant_id', $fixture['grant']->id)
            ->where('status', 'started')
            ->count())
        ->toBe($authorization->isSuccessful() ? 1 : 0)
        ->and(fn () => app(DownloadAuthorizationService::class)->authorize(
            $fixture['grant_public_id'],
            $fixture['grant_token'],
            new DownloadRequestContext(str_repeat('b', 64), 1, null),
        ))->toThrow(DownloadAccessDenied::class);
});

it('C3 lets simultaneous ranges reuse one attempt without another quota unit', function (): void {
    $fixture = p4c456DeliverableGrant();
    $attempt = app(DownloadAuthorizationService::class)->authorize(
        $fixture['grant_public_id'],
        $fixture['grant_token'],
        new DownloadRequestContext(str_repeat('c', 64), 1, 'P4C456'),
    );
    $payload = array_merge(p4c456Payload($fixture), ['attempt_token' => $attempt->rawAttemptToken]);
    $first = p4c456Process('file', array_merge($payload, ['range' => 'bytes=0-1023']));
    $second = p4c456Process('file', array_merge($payload, ['range' => 'bytes=1024-2047']));

    p4c456ReleaseOrderLockAfterProcessesStart($fixture['order_id'], [$first, $second]);

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(DownloadLog::query()->where('download_grant_id', $fixture['grant']->id)->count())->toBe(1)
        ->and(DownloadLog::query()->where('download_grant_id', $fixture['grant']->id)->value('status'))->toBe('completed')
        ->and($fixture['grant']->refresh()->downloads_count)->toBe(1);
});

it('C4 grants the final quota unit once under simultaneous authorization', function (): void {
    $fixture = p4c456DeliverableGrant(maxDownloads: 1);
    $payload = p4c456Payload($fixture);
    $first = p4c456Process('authorize', $payload);
    $second = p4c456Process('authorize', $payload);

    p4c456ReleaseOrderLockAfterProcessesStart($fixture['order_id'], [$first, $second]);

    $codes = [$first->getExitCode(), $second->getExitCode()];
    sort($codes);

    expect($codes)->toBe([0, 2])
        ->and($fixture['grant']->refresh()->downloads_count)->toBe(1)
        ->and(DownloadLog::query()
            ->where('download_grant_id', $fixture['grant']->id)
            ->where('quota_consumed', true)
            ->count())->toBe(1)
        ->and(DownloadLog::query()
            ->where('download_grant_id', $fixture['grant']->id)
            ->where('status', 'denied')
            ->where('quota_consumed', false)
            ->count())->toBe(1);
});

it('C5 never purges the log required by an active file preparation', function (): void {
    $fixture = p4c456DeliverableGrant();
    $attempt = app(DownloadAuthorizationService::class)->authorize(
        $fixture['grant_public_id'],
        $fixture['grant_token'],
        new DownloadRequestContext(str_repeat('d', 64), 1, null),
    );
    $payload = array_merge(p4c456Payload($fixture), [
        'attempt_token' => $attempt->rawAttemptToken,
        'range' => 'bytes=0-1023',
    ]);
    $file = p4c456Process('file', $payload);
    $purge = p4c456Process('purge', $payload);
    $locker = p4c456RuntimePdo();

    try {
        $locker->beginTransaction();
        $statement = $locker->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
        $statement->execute([$fixture['order_id']]);

        $file->start();
        $purge->start();
        usleep(500_000);
        expect($file->isRunning())->toBeTrue();

        $locker->commit();
        $file->wait();
        $purge->wait();
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        if ($file->isRunning()) {
            $file->stop(1);
        }
        if ($purge->isRunning()) {
            $purge->stop(1);
        }
        $locker = null;
    }

    expect($file->isSuccessful())->toBeTrue($file->getErrorOutput())
        ->and($purge->isSuccessful())->toBeTrue($purge->getErrorOutput())
        ->and($purge->getOutput())->toBe('0')
        ->and(DownloadLog::query()->where('download_grant_id', $fixture['grant']->id)->count())->toBe(1)
        ->and($fixture['grant']->refresh()->downloads_count)->toBe(1);
});

it('C6 makes simultaneous reconciliation a single idempotent transition', function (): void {
    $fixture = p4c456DeliverableGrant();
    $old = now()->subHours(2)->toImmutable();
    app(DownloadAuthorizationService::class)->authorize(
        $fixture['grant_public_id'],
        $fixture['grant_token'],
        new DownloadRequestContext(str_repeat('e', 64), 1, null),
        $old,
    );
    $payload = p4c456Payload($fixture);
    $first = p4c456Process('reconcile', $payload);
    $second = p4c456Process('reconcile', $payload);

    p4c456ReleaseOrderLockAfterProcessesStart($fixture['order_id'], [$first, $second]);

    $transitions = [(int) $first->getOutput(), (int) $second->getOutput()];
    sort($transitions);
    $log = DownloadLog::query()->where('download_grant_id', $fixture['grant']->id)->sole();

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and($transitions)->toBe([0, 1])
        ->and($log->status)->toBe('denied')
        ->and($log->denial_reason_code)->toBe('delivery_interrupted')
        ->and($fixture['grant']->refresh()->downloads_count)->toBe(1);
});
