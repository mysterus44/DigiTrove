<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Delivery\GrantIssuanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| P4-C — Real-concurrency proofs on independent runtime connections (D-035)
|--------------------------------------------------------------------------
*/

function p4cRuntimePdo(): PDO
{
    $c = config('database.connections.pgsql');

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'] ?? 5432, $c['database']),
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function p4cServiceProcess(string $operation, int $id, string $reference = ''): Process
{
    $script = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\DB::statement("SET lock_timeout = '10s'");
        config([
            'delivery.enabled' => true,
            'delivery.grant.ttl_minutes' => 10080,
            'delivery.grant.max_downloads' => 5,
            'delivery.job.unique_seconds' => 3600,
            'delivery.download_base_url' => 'https://dl.example.com/d',
            'delivery.require_https' => true,
        ]);

        try {
            if ($argv[1] === 'issue') {
                (new App\Services\Delivery\GrantIssuanceService)->issueForOrder((int) $argv[2]);
            } elseif ($argv[1] === 'refund') {
                (new App\Services\Delivery\RefundCompletionService)->completeSucceededRefund(
                    (int) $argv[2],
                    $argv[3],
                );
            } else {
                throw new RuntimeException('Unknown P4-C concurrency operation.');
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception::class.':'.$exception->getMessage());
            exit(2);
        }
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, $operation, (string) $id, $reference],
        base_path(),
        null,
        null,
        60,
    );
}

beforeEach(function (): void {
    p4cConfig();
});

// C1 — two jobs for the same order: the order lock serialises issuance and a
// re-run leaves exactly one active batch (no duplicate active pair).
it('C1 — two issuance processes wait on the Order lock and leave one active batch', function (): void {
    $product = p4cProduct();
    p4cFile($product, name: 'a.zip');
    p4cFile($product, name: 'b.zip');
    ['order' => $order] = p4cDeliverableOrder($product);

    $locker = p4cRuntimePdo();
    $first = p4cServiceProcess('issue', $order->id);
    $second = p4cServiceProcess('issue', $order->id);

    try {
        $locker->beginTransaction();
        $statement = $locker->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
        $statement->execute([$order->id]);

        $first->start();
        $second->start();
        usleep(500_000);

        expect($first->isRunning())->toBeTrue()
            ->and($second->isRunning())->toBeTrue();

        $locker->commit();
        $first->wait();
        $second->wait();
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        if ($first->isRunning()) {
            $first->stop(1);
        }
        if ($second->isRunning()) {
            $second->stop(1);
        }
        $locker = null;
    }

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(2)
        ->and(DownloadGrant::query()->whereNotNull('revoked_at')->count())->toBe(2);
    // No two active grants share a pair (the DB partial unique guarantees it).
    $pairs = DownloadGrant::query()->whereNull('revoked_at')->get()
        ->map(fn ($g) => $g->order_item_id.':'.$g->product_file_id);
    expect($pairs->unique()->count())->toBe($pairs->count());
});

// C2 — issuance vs a full refund: once the order is refunded, no active grant
// remains and a further issuance is refused.
it('C2 — concurrent issuance and full refund can never leave a refunded order with an active grant', function (): void {
    $product = p4cProduct();
    p4cFile($product);
    ['order' => $order] = p4cDeliverableOrder($product);

    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
    $refund = DB::transaction(fn () => Refund::factory()->create([
        'public_id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'provider' => $payment->provider,
        'provider_refund_reference' => null, 'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
        'amount_minor' => 15_000, 'currency' => $payment->currency, 'status' => RefundStatus::Pending,
        'requested_at' => now(),
    ]));

    $locker = p4cRuntimePdo();
    $issuance = p4cServiceProcess('issue', $order->id);
    $completion = p4cServiceProcess('refund', $refund->id, 'REF-FULL');

    try {
        $locker->beginTransaction();
        $statement = $locker->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
        $statement->execute([$order->id]);

        $issuance->start();
        $completion->start();
        usleep(500_000);

        expect($issuance->isRunning())->toBeTrue()
            ->and($completion->isRunning())->toBeTrue();

        $locker->commit();
        $issuance->wait();
        $completion->wait();
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        if ($issuance->isRunning()) {
            $issuance->stop(1);
        }
        if ($completion->isRunning()) {
            $completion->stop(1);
        }
        $locker = null;
    }

    expect($completion->isSuccessful())->toBeTrue($completion->getErrorOutput())
        ->and(in_array($issuance->getExitCode(), [0, 2], true))->toBeTrue()
        ->and($issuance->getErrorOutput())->not->toContain('SQLSTATE')
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(fn () => (new GrantIssuanceService)->issueForOrder($order->id))->toThrow(RuntimeException::class);
});

it('C3 — concurrent completion of one refund is idempotent and revokes grants once', function (): void {
    $product = p4cProduct();
    p4cFile($product);
    ['order' => $order] = p4cDeliverableOrder($product);
    (new GrantIssuanceService)->issueForOrder($order->id);

    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
    $refund = DB::transaction(fn () => Refund::factory()->create([
        'public_id' => (string) Str::uuid(), 'payment_id' => $payment->id, 'provider' => $payment->provider,
        'provider_refund_reference' => null, 'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
        'amount_minor' => 15_000, 'currency' => $payment->currency, 'status' => RefundStatus::Pending,
        'requested_at' => now(),
    ]));

    $locker = p4cRuntimePdo();
    $first = p4cServiceProcess('refund', $refund->id, 'REF-SAME');
    $second = p4cServiceProcess('refund', $refund->id, 'REF-SAME');

    try {
        $locker->beginTransaction();
        $statement = $locker->prepare('SELECT id FROM payments WHERE id = ? FOR UPDATE');
        $statement->execute([$payment->id]);

        $first->start();
        $second->start();
        usleep(500_000);
        expect($first->isRunning())->toBeTrue()
            ->and($second->isRunning())->toBeTrue();

        $locker->commit();
        $first->wait();
        $second->wait();
    } finally {
        if ($locker->inTransaction()) {
            $locker->rollBack();
        }
        if ($first->isRunning()) {
            $first->stop(1);
        }
        if ($second->isRunning()) {
            $second->stop(1);
        }
        $locker = null;
    }

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and($refund->refresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->provider_refund_reference)->toBe('REF-SAME')
        ->and($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(0)
        ->and(DownloadGrant::query()->where('revoked_reason_code', 'full_refund')->count())->toBe(1);
});

// C5 — a raw duplicate active grant for the same purchased pair is refused by
// the partial unique index (23505), never by a fragile application check.
it('C5 — a duplicate active grant for a pair is refused by download_grants_active_pair_unique', function (): void {
    $product = p4cProduct();
    p4cFile($product);
    ['order' => $order] = p4cDeliverableOrder($product);
    (new GrantIssuanceService)->issueForOrder($order->id);

    $existing = DownloadGrant::query()->firstOrFail();

    $pdo = p4cRuntimePdo();
    $conflict = null;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO download_grants (public_id, order_item_id, product_file_id, user_id, token_hash, expires_at, max_downloads, downloads_count, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, now() + interval \'1 day\', 5, 0, now(), now())'
        );
        $stmt->execute([
            (string) Str::uuid(),
            $existing->order_item_id,
            $existing->product_file_id,
            hash('sha256', (string) Str::uuid()),
        ]);
    } catch (PDOException $e) {
        $conflict = $e;
    }
    $pdo = null;

    $probe = p4cRuntimePdo();
    $probeValue = (int) $probe->query('SELECT 1')->fetchColumn();
    $probe = null;

    expect($conflict)->not->toBeNull('A duplicate active grant was allowed.')
        ->and($conflict->getCode())->toBe('23505')
        ->and($conflict->getMessage())->toContain('download_grants_active_pair_unique')
        ->and($probeValue)->toBe(1);
});
