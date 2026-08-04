<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

function p6a10ProcessWorker(int $orderId): Process
{
    $connection = config('database.connections.pgsql');
    $code = <<<'PHP'
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('TEST_DB_HOST'), getenv('TEST_DB_PORT'), getenv('TEST_DB_NAME')),
            getenv('TEST_DB_USER'), getenv('TEST_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $statement = $pdo->prepare('SELECT * FROM public.process_crm_order_attribution(:order_id::bigint)');
        $statement->execute(['order_id' => getenv('TEST_ORDER_ID')]);
        echo json_encode($statement->fetch(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
        PHP;

    return new Process([PHP_BINARY, '-r', $code], null, [
        'TEST_DB_HOST' => (string) $connection['host'],
        'TEST_DB_PORT' => (string) ($connection['port'] ?? 5432),
        'TEST_DB_NAME' => (string) $connection['database'],
        'TEST_DB_USER' => (string) $connection['username'],
        'TEST_DB_PASSWORD' => (string) $connection['password'],
        'TEST_ORDER_ID' => (string) $orderId,
    ]);
}

it('serializes two processors for one order to one attribution', function () {
    $order = $this->crmPendingOrder('same-order-race@example.test');
    p6a10Acquire($order);
    $first = p6a10ProcessWorker($order->id);
    $second = p6a10ProcessWorker($order->id);

    try {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and(DB::connection('pgsql_migration')->table('crm_order_attributions')->where('order_id', $order->id)->count())->toBe(1)
            ->and(DB::connection('pgsql_migration')->table('crm_contacts')->where('email', 'same-order-race@example.test')->count())->toBe(1)
            ->and(DB::connection('pgsql_migration')->table('crm_order_attribution_outbox')->where('order_id', $order->id)->value('attempt_count'))->toBe(1);
    } finally {
        $first->stop(0.1);
        $second->stop(0.1);
    }
});

it('serializes two different orders with the same email to one contact without deadlock', function () {
    $firstOrder = $this->crmPendingOrder('shared-contact-race@example.test');
    $secondOrder = $this->crmPendingOrder('shared-contact-race@example.test');
    p6a10Acquire($firstOrder);
    p6a10Acquire($secondOrder);
    $first = p6a10ProcessWorker($firstOrder->id);
    $second = p6a10ProcessWorker($secondOrder->id);

    try {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
            ->and(DB::connection('pgsql_migration')->table('crm_contacts')->where('email', 'shared-contact-race@example.test')->count())->toBe(1)
            ->and(DB::connection('pgsql_migration')->table('crm_order_attributions')->count())->toBe(2);
    } finally {
        $first->stop(0.1);
        $second->stop(0.1);
    }
});
