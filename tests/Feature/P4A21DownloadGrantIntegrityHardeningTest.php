<?php

use App\Enums\OrderStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

function forceP4A21Constraints(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function expectP4A21QueryException(Closure $callback, string $sqlState, string $messageFragment): void
{
    $exception = null;

    try {
        DB::transaction(function () use ($callback): void {
            $callback();
            forceP4A21Constraints();
        });
    } catch (QueryException $queryException) {
        $exception = $queryException;
    } finally {
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe($sqlState)
        ->and($exception->getMessage())->toContain($messageFragment);
}

function expectP4A21TriggerViolation(Closure $callback, string $messageFragment): void
{
    expectP4A21QueryException($callback, '23514', $messageFragment);
}

/**
 * @return array{order: Order, item: OrderItem, product: Product, file: ProductFile}
 */
function createP4A21DirectPurchase(OrderStatus $status = OrderStatus::Paid, ?User $user = null): array
{
    return DB::transaction(function () use ($status, $user): array {
        $product = Product::factory()->create();
        $file = ProductFile::factory()->create(['product_id' => $product->getKey()]);
        $order = Order::factory()->create([
            'status' => $status,
            'paid_at' => $status === OrderStatus::Paid ? now() : null,
            'user_id' => $user?->getKey(),
        ]);
        $item = OrderItem::factory()->forOrder($order)->forProduct($product)->create();

        if ($status === OrderStatus::Paid) {
            Payment::factory()->forOrder($order)->succeeded()->create();
        }

        forceP4A21Constraints();

        return compact('order', 'item', 'product', 'file');
    });
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function p4a21GrantPayload(array $purchase, array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::uuid(),
        'order_item_id' => $purchase['item']->getKey(),
        'product_file_id' => $purchase['file']->getKey(),
        'user_id' => $purchase['order']->user_id,
        'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
        'expires_at' => now()->addDay(),
        'max_downloads' => 5,
        'downloads_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function p4a21FunctionDefinition(PDO $pdo, string $function): string
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT pg_get_functiondef(p.oid)
        FROM pg_proc p
        JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.proname = :function
        SQL);
    $statement->execute(['function' => $function]);

    return (string) $statement->fetchColumn();
}

function runP4A21Migration(string $database, string $command, string $migration): void
{
    $process = new Process(
        [
            PHP_BINARY,
            'artisan',
            $command,
            '--env=testing',
            '--force',
            // P4-B0: gate migrations always run under the migrator/owner identity.
            '--database=pgsql_migration',
            '--path=database/migrations/'.$migration,
        ],
        base_path(),
        [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql_migration',
            'DB_DATABASE' => $database,
        ],
    );
    $process->setTimeout(120);
    $process->run();

    expect($process->isSuccessful())->toBeTrue(
        $process->getOutput().$process->getErrorOutput(),
    );
}

it('applies additive migration 000011 without changing the P4-A2 object topology', function () {
    expect(DB::table('migrations')->count())->toBe(52)
        ->and(DB::table('migrations')->where('migration', '2026_07_14_000011_harden_download_grants_integrity')->exists())->toBeTrue()
        ->and(Schema::hasTable('download_grants'))->toBeTrue()
        ->and(Schema::hasTable('licenses'))->toBeFalse();

    $functions = [
        'prevent_download_grants_delete',
        'enforce_download_grants_immutability',
        'validate_download_grant_delivery',
        'validate_download_grant_order_consistency',
    ];
    $triggers = [
        'download_grants_prevent_delete_trigger',
        'download_grants_enforce_immutability_trigger',
        'download_grants_validate_delivery_trigger',
        'download_grants_validate_order_consistency_trigger',
        'orders_validate_download_consistency_trigger',
    ];

    expect(DB::table('pg_proc')->whereIn('proname', $functions)->count())->toBe(4)
        ->and(DB::table('pg_trigger')->whereIn('tgname', $triggers)->where('tgisinternal', false)->count())->toBe(5);

    $signatures = DB::table('pg_proc')
        ->whereIn('proname', ['enforce_download_grants_immutability', 'validate_download_grant_delivery'])
        ->selectRaw('proname, pg_get_function_identity_arguments(oid) AS signature')
        ->pluck('signature', 'proname');

    expect($signatures['enforce_download_grants_immutability'])->toBe('')
        ->and($signatures['validate_download_grant_delivery'])->toBe('');

    $g2 = (string) DB::selectOne(
        "SELECT pg_get_functiondef(oid) AS definition FROM pg_proc WHERE proname = 'enforce_download_grants_immutability'",
    )->definition;
    $g3 = (string) DB::selectOne(
        "SELECT pg_get_functiondef(oid) AS definition FROM pg_proc WHERE proname = 'validate_download_grant_delivery'",
    )->definition;

    expect($g2)->toContain('updated_at may only change with a valid lifecycle transition')
        ->and($g2)->toContain('updated_at must move strictly forward')
        ->and($g2)->toContain('user_fk_nullification')
        ->and($g2)->toContain('downloads_count may only increase by exactly one')
        ->and($g2)->toContain('revocation is irreversible')
        ->and($g3)->toContain('NEW.user_id IS NOT DISTINCT FROM order_user_id')
        ->and($g3)->not->toContain('order_user_id IS NOT NULL AND NEW.user_id IS DISTINCT FROM order_user_id')
        ->and($g3)->toContain('FOR UPDATE OF o')
        ->and($g3)->not->toContain('payments');

    $bindings = DB::table('pg_trigger as t')
        ->join('pg_proc as p', 'p.oid', '=', 't.tgfoid')
        ->whereIn('t.tgname', [
            'download_grants_enforce_immutability_trigger',
            'download_grants_validate_delivery_trigger',
        ])
        ->pluck('p.proname', 't.tgname');

    expect($bindings['download_grants_enforce_immutability_trigger'])->toBe('enforce_download_grants_immutability')
        ->and($bindings['download_grants_validate_delivery_trigger'])->toBe('validate_download_grant_delivery');
});

it('enforces the complete null-safe beneficiary matrix for guest and authenticated orders', function () {
    $guestAccepted = createP4A21DirectPurchase();

    DB::transaction(function () use ($guestAccepted): void {
        DB::table('download_grants')->insert(p4a21GrantPayload($guestAccepted, ['user_id' => null]));
        forceP4A21Constraints();
    });
    expect(DB::table('download_grants')->where('order_item_id', $guestAccepted['item']->id)->value('user_id'))->toBeNull();

    $arbitrary = User::factory()->create();
    $guestRejected = createP4A21DirectPurchase();
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->insert(p4a21GrantPayload($guestRejected, ['user_id' => $arbitrary->id])),
        'download_grants user must match the order buyer',
    );
    expect(DB::table('download_grants')->where('order_item_id', $guestRejected['item']->id)->count())->toBe(0);

    // Eloquent reaches the same G3 trigger and cannot attach the guest grant either.
    $eloquentGuest = createP4A21DirectPurchase();
    expectP4A21TriggerViolation(
        fn () => DownloadGrant::factory()->forOrderItem($eloquentGuest['item'])->forProductFile($eloquentGuest['file'])->create(['user_id' => $arbitrary->id]),
        'download_grants user must match the order buyer',
    );

    $buyer = User::factory()->create();
    $authenticatedAccepted = createP4A21DirectPurchase(OrderStatus::Paid, $buyer);
    expect(DownloadGrant::factory()->forOrderItem($authenticatedAccepted['item'])->forProductFile($authenticatedAccepted['file'])->create(['user_id' => $buyer->id])->exists)->toBeTrue();
    forceP4A21Constraints();

    $authenticatedNull = createP4A21DirectPurchase(OrderStatus::Paid, $buyer);
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->insert(p4a21GrantPayload($authenticatedNull, ['user_id' => null])),
        'download_grants user must match the order buyer',
    );

    $authenticatedOther = createP4A21DirectPurchase(OrderStatus::Paid, $buyer);
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->insert(p4a21GrantPayload($authenticatedOther, ['user_id' => $arbitrary->id])),
        'download_grants user must match the order buyer',
    );
});

it('rejects isolated updated_at falsification while accepting an identical assignment', function () {
    $purchase = createP4A21DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create();
    $original = DB::table('download_grants')->where('id', $grant->id)->first();

    expectP4A21TriggerViolation(
        fn () => DB::statement('UPDATE download_grants SET updated_at = updated_at + interval \'1 day\' WHERE id = ?', [$grant->id]),
        'download_grants updated_at may only change with a valid lifecycle transition',
    );
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update(['updated_at' => now()->subDay()]),
        'download_grants updated_at must move strictly forward',
    );

    expect(DB::table('download_grants')->where('id', $grant->id)->first())->toEqual($original);

    expect(DB::table('download_grants')->where('id', $grant->id)->update(['updated_at' => $original->updated_at]))->toBe(1)
        ->and(DB::table('download_grants')->where('id', $grant->id)->first())->toEqual($original);
});

it('allows updated_at to advance only with consumption or revocation and keeps valid FK nullification independent', function () {
    // Since P4-B, a DIRECT consumption (even with a coherent updated_at) is
    // refused at trigger depth 1: only the nested G5 UPDATE may consume, and the
    // P4-B suite proves updated_at then advances strictly with it.
    $consumptionPurchase = createP4A21DirectPurchase();
    $consumption = DownloadGrant::factory()->forOrderItem($consumptionPurchase['item'])->forProductFile($consumptionPurchase['file'])->create();
    $consumptionBefore = DB::table('download_grants')->where('id', $consumption->id)->first();

    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $consumption->id)->update([
            'downloads_count' => 1,
            'updated_at' => now()->addMinute(),
        ]),
        'download_grants consumption must originate from the download log executor',
    );
    expect(DB::table('download_grants')->where('id', $consumption->id)->first())->toEqual($consumptionBefore);

    $revocationPurchase = createP4A21DirectPurchase();
    $revocation = DownloadGrant::factory()->forOrderItem($revocationPurchase['item'])->forProductFile($revocationPurchase['file'])->create();
    expect(DB::table('download_grants')->where('id', $revocation->id)->update([
        'revoked_at' => now(),
        'revoked_reason_code' => 'support',
        'updated_at' => now()->addMinute(),
    ]))->toBe(1);

    $backwardRevocationPurchase = createP4A21DirectPurchase();
    $backwardRevocation = DownloadGrant::factory()->forOrderItem($backwardRevocationPurchase['item'])->forProductFile($backwardRevocationPurchase['file'])->create();
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $backwardRevocation->id)->update([
            'revoked_at' => now(),
            'revoked_reason_code' => 'support',
            'updated_at' => now()->subMinute(),
        ]),
        'download_grants updated_at must move strictly forward',
    );
    expect(DB::table('download_grants')->where('id', $backwardRevocation->id)->value('revoked_at'))->toBeNull();

    $buyer = User::factory()->create();
    $fkPurchase = createP4A21DirectPurchase(OrderStatus::Paid, $buyer);
    $fkGrant = DownloadGrant::factory()->forOrderItem($fkPurchase['item'])->forProductFile($fkPurchase['file'])->create();
    $fkUpdatedAt = DB::table('download_grants')->where('id', $fkGrant->id)->value('updated_at');

    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $fkGrant->id)->update(['user_id' => null]),
        'download_grants user reference may only be nulled by the foreign key action',
    );

    DB::table('users')->where('id', $buyer->id)->delete();
    $afterFk = DB::table('download_grants')->where('id', $fkGrant->id)->first();
    expect($afterFk->user_id)->toBeNull()
        ->and($afterFk->updated_at)->toBe($fkUpdatedAt)
        ->and($afterFk->token_hash)->toBe($fkGrant->token_hash)
        ->and($afterFk->downloads_count)->toBe(0)
        ->and($afterFk->revoked_at)->toBeNull();
});

it('rejects adversarial multi-column updates atomically', function () {
    $purchase = createP4A21DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create(['max_downloads' => 5]);
    $original = DB::table('download_grants')->where('id', $grant->id)->first();

    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update([
            'token_hash' => hash('sha256', 'tampered'),
            'updated_at' => now()->addDay(),
        ]),
        'download_grants identity, token and bounds are immutable',
    );

    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update([
            'downloads_count' => 2,
            'updated_at' => now()->addDay(),
        ]),
        'download_grants downloads_count may only increase by exactly one',
    );

    expect(DB::table('download_grants')->where('id', $grant->id)->first())->toEqual($original);
});

it('preserves the original G2 quota, expiry and irreversible revocation rules', function () {
    $purchase = createP4A21DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create(['max_downloads' => 2]);

    // Since P4-B the direct +1 is refused at depth 1; wrong deltas keep their
    // precise diagnostics, and the P4-B suite proves quota exhaustion through G5.
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update(['downloads_count' => -1]),
        'download_grants downloads_count may only increase by exactly one',
    );
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update(['downloads_count' => 3]),
        'download_grants downloads_count may only increase by exactly one',
    );
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update(['downloads_count' => 1]),
        'download_grants consumption must originate from the download log executor',
    );
    expect((int) DB::table('download_grants')->where('id', $grant->id)->value('downloads_count'))->toBe(0);

    $expiredPurchase = createP4A21DirectPurchase();
    $expired = DownloadGrant::factory()->forOrderItem($expiredPurchase['item'])->forProductFile($expiredPurchase['file'])->expired()->create();
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $expired->id)->update(['downloads_count' => 1]),
        'download_grants cannot be consumed once expired',
    );

    $revokedPurchase = createP4A21DirectPurchase();
    $revoked = DownloadGrant::factory()->forOrderItem($revokedPurchase['item'])->forProductFile($revokedPurchase['file'])->create();
    DB::table('download_grants')->where('id', $revoked->id)->update([
        'revoked_at' => now(),
        'revoked_reason_code' => 'support',
    ]);
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $revoked->id)->update(['downloads_count' => 1]),
        'download_grants cannot be consumed once revoked',
    );
    expectP4A21TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $revoked->id)->update(['revoked_at' => now()->addHour()]),
        'download_grants revocation is irreversible',
    );
});

it('rolls back only 000011 and restores the exact original G2 and G3 definitions', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4a21_rollback_'.strtolower(Str::random(10)));
    $hotfix = '2026_07_14_000011_harden_download_grants_integrity.php';
    $functions = [
        'prevent_download_grants_delete',
        'enforce_download_grants_immutability',
        'validate_download_grant_delivery',
        'validate_download_grant_order_consistency',
    ];
    $triggers = [
        'download_grants_prevent_delete_trigger',
        'download_grants_enforce_immutability_trigger',
        'download_grants_validate_delivery_trigger',
        'download_grants_validate_order_consistency_trigger',
        'orders_validate_download_consistency_trigger',
    ];
    $pdo = null;

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough('2026_07_14_000010_create_download_grants_table.php');
        expect(end($applied))->toBe('2026_07_14_000010_create_download_grants_table');

        $connection = config('database.connections.pgsql_migration');
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName()),
            $connection['username'],
            $connection['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $originalG2 = p4a21FunctionDefinition($pdo, 'enforce_download_grants_immutability');
        $originalG3 = p4a21FunctionDefinition($pdo, 'validate_download_grant_delivery');

        // Seed one valid grant before the hotfix; replacing function definitions must
        // never rewrite or remove existing authorisations.
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES ('p4a21-rb-product', 'P4A21 rollback product', 'ebook', 'published', now(), now())");
        $pdo->exec("INSERT INTO product_files (product_id, storage_disk, storage_path, original_name, size_bytes, checksum_sha256, version) SELECT id, 'private', 'products/p4a21-rb/file.zip', 'file.zip', 10, '".hash('sha256', 'p4a21-rb-file')."', '1.0' FROM products WHERE slug = 'p4a21-rb-product'");
        $pdo->exec("INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email, subtotal_minor, discount_minor, tax_minor, total_minor, currency, status, placed_at, expires_at, paid_at, created_at, updated_at) VALUES (gen_random_uuid(), 'DGT-2026-HRB0000001', '".hash('sha256', 'p4a21-rb-order')."', 'p4a21-rb@example.test', 1000, 0, 0, 1000, 'XOF', 'paid', now(), now() + interval '30 minutes', now(), now(), now())");
        $pdo->exec("INSERT INTO order_items (order_id, product_id, product_name_snapshot, product_slug_snapshot, product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor, line_total_minor, currency, created_at, updated_at) SELECT o.id, p.id, p.name, p.slug, p.type, 1000, 1, 1000, 0, 1000, 'XOF', now(), now() FROM orders o, products p WHERE o.order_number = 'DGT-2026-HRB0000001' AND p.slug = 'p4a21-rb-product'");
        $pdo->exec("INSERT INTO payments (public_id, order_id, provider, idempotency_key_hash, attempt_number, amount_minor, currency, status, succeeded_at, created_at, updated_at) SELECT gen_random_uuid(), o.id, 'provider_test', '".hash('sha256', 'p4a21-rb-payment')."', 1, 1000, 'XOF', 'succeeded', now(), now(), now() FROM orders o WHERE o.order_number = 'DGT-2026-HRB0000001'");
        $pdo->exec("INSERT INTO download_grants (public_id, order_item_id, product_file_id, token_hash, expires_at, max_downloads, created_at, updated_at) SELECT gen_random_uuid(), oi.id, pf.id, '".hash('sha256', 'p4a21-rb-token')."', now() + interval '1 day', 5, now(), now() FROM order_items oi JOIN product_files pf ON pf.product_id = oi.product_id WHERE oi.product_slug_snapshot = 'p4a21-rb-product'");
        $pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
        $pdo->commit();

        runP4A21Migration($harness->databaseName(), 'migrate', $hotfix);

        $hardenedG2 = p4a21FunctionDefinition($pdo, 'enforce_download_grants_immutability');
        $hardenedG3 = p4a21FunctionDefinition($pdo, 'validate_download_grant_delivery');
        expect($hardenedG2)->not->toBe($originalG2)
            ->and($hardenedG2)->toContain('updated_at may only change with a valid lifecycle transition')
            ->and($hardenedG3)->not->toBe($originalG3)
            ->and($hardenedG3)->toContain('NEW.user_id IS NOT DISTINCT FROM order_user_id')
            ->and($harness->countFunctions($functions))->toBe(4)
            ->and($harness->countTriggers($triggers))->toBe(5)
            ->and((int) $pdo->query('SELECT COUNT(*) FROM download_grants')->fetchColumn())->toBe(1);

        $downed = $harness->rollbackExactMigrations([$hotfix]);
        expect($downed)->toBe(['2026_07_14_000011_harden_download_grants_integrity'])
            ->and(p4a21FunctionDefinition($pdo, 'enforce_download_grants_immutability'))->toBe($originalG2)
            ->and(p4a21FunctionDefinition($pdo, 'validate_download_grant_delivery'))->toBe($originalG3)
            ->and($harness->countFunctions($functions))->toBe(4)
            ->and($harness->countTriggers($triggers))->toBe(5)
            ->and($harness->hasTable('download_grants'))->toBeTrue()
            ->and($harness->hasTable('order_item_bundle_components'))->toBeTrue()
            ->and($harness->countFunctions(['enforce_product_file_content_immutability']))->toBe(1)
            ->and((int) $pdo->query('SELECT COUNT(*) FROM download_grants')->fetchColumn())->toBe(1)
            ->and($harness->hasTable('download_logs'))->toBeFalse();

        $remaining = $harness->ranMigrations();
        expect(end($remaining))->toBe('2026_07_14_000010_create_download_grants_table')
            ->and($remaining)->not->toContain('2026_07_14_000011_harden_download_grants_integrity')
            ->and($remaining)->not->toContain('2026_07_14_000012_create_download_logs_table');

        $pdo = null;
    } finally {
        $pdo = null;
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});
