<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithCrmDatabase;

uses(InteractsWithCrmDatabase::class);

function p6a0ConcurrencyProcess(string $code, array $values): Process
{
    $connection = config('database.connections.pgsql');

    return new Process(
        [PHP_BINARY, '-r', $code],
        null,
        array_merge([
            'TEST_DB_HOST' => (string) $connection['host'],
            'TEST_DB_PORT' => (string) ($connection['port'] ?? 5432),
            'TEST_DB_NAME' => (string) $connection['database'],
            'TEST_DB_USER' => (string) $connection['username'],
            'TEST_DB_PASSWORD' => (string) $connection['password'],
        ], $values),
    );
}

it('serializes concurrent exact-email resolution to one active contact', function () {
    $order = $this->crmPendingOrder('race@example.test');
    $code = <<<'PHP'
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('TEST_DB_HOST'), getenv('TEST_DB_PORT'), getenv('TEST_DB_NAME')),
            getenv('TEST_DB_USER'), getenv('TEST_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $stmt = $pdo->prepare("SELECT * FROM public.resolve_crm_contact(:email::varchar, 'guest_order'::varchar, NULL::bigint, :order_id::bigint)");
        $stmt->execute(['email' => getenv('TEST_EMAIL'), 'order_id' => getenv('TEST_ORDER_ID')]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
        PHP;

    $first = p6a0ConcurrencyProcess($code, [
        'TEST_EMAIL' => ' Race@Example.Test ',
        'TEST_ORDER_ID' => (string) $order->id,
    ]);
    $second = p6a0ConcurrencyProcess($code, [
        'TEST_EMAIL' => 'race@example.test',
        'TEST_ORDER_ID' => (string) $order->id,
    ]);

    try {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());

        $firstRow = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $secondRow = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($firstRow['contact_id'])->toBe($secondRow['contact_id'])
            ->and($firstRow['public_id'])->toBe($secondRow['public_id'])
            ->and(DB::connection('pgsql_migration')->table('crm_contacts')->count())->toBe(1);
    } finally {
        $first->stop(0.1);
        $second->stop(0.1);
    }
});

it('serializes consent writes and keeps idempotent replay single-row', function () {
    $user = User::factory()->create(['email' => 'consent-race@example.test']);
    $contact = DB::selectOne(
        "SELECT * FROM public.resolve_crm_contact(?::varchar, 'verified_account'::varchar, ?::bigint, NULL::bigint)",
        [$user->email, $user->id],
    );
    $hash = hash('sha256', 'concurrent-consent-key');
    $code = <<<'PHP'
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('TEST_DB_HOST'), getenv('TEST_DB_PORT'), getenv('TEST_DB_NAME')),
            getenv('TEST_DB_USER'), getenv('TEST_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $stmt = $pdo->prepare("SELECT * FROM public.record_crm_marketing_consent(:contact::uuid, 'granted'::varchar, 'account_settings'::varchar, :user_id::bigint, NULL::bigint, '2026-08-v1'::varchar, :digest::varchar)");
        $stmt->execute(['contact' => getenv('TEST_CONTACT'), 'user_id' => getenv('TEST_USER_ID'), 'digest' => getenv('TEST_DIGEST')]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
        PHP;
    $environment = [
        'TEST_CONTACT' => $contact->public_id,
        'TEST_USER_ID' => (string) $user->id,
        'TEST_DIGEST' => $hash,
    ];
    $first = p6a0ConcurrencyProcess($code, $environment);
    $second = p6a0ConcurrencyProcess($code, $environment);

    try {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
            ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput());

        $firstRow = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $secondRow = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($firstRow['event_public_id'])->toBe($secondRow['event_public_id'])
            ->and(collect([$firstRow['inserted'], $secondRow['inserted']])->sort()->values()->all())
            ->toBe([false, true])
            ->and(DB::connection('pgsql_migration')->table('crm_marketing_consent_events')->count())->toBe(1);
    } finally {
        $first->stop(0.1);
        $second->stop(0.1);
    }
});
