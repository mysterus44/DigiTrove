<?php

use App\Enums\OrderStatus;
use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

function forceP4BConstraints(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function expectP4BQueryException(Closure $callback, string $sqlState, string $messageFragment): void
{
    $exception = null;

    try {
        DB::transaction(function () use ($callback): void {
            $callback();
            forceP4BConstraints();
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

function expectP4BTriggerViolation(Closure $callback, string $messageFragment): void
{
    expectP4BQueryException($callback, '23514', $messageFragment);
}

/**
 * The migrator/owner connection. Used only for the internal G2 probes that must
 * REACH the trigger layer, since the restricted runtime is stopped earlier at the
 * ACL layer (42501). Neither layer is validated by the superuser alone: the ACL
 * refusals below run under the real runtime role.
 */
function p4bOwner(): Connection
{
    return DB::connection('pgsql_migration');
}

/**
 * Runs a runtime statement expected to be refused at the ACL layer (42501). The
 * probe is wrapped in a savepoint so its failure does not abort the surrounding
 * RefreshDatabase transaction. A permission-denied answer is returned before any
 * row is examined, so the target row need not be visible to this connection.
 */
function expectP4BRuntimeDenied(string $sql, array $bindings = []): void
{
    $state = null;

    try {
        DB::transaction(function () use ($sql, $bindings): void {
            DB::statement($sql, $bindings);
        });
    } catch (QueryException $e) {
        $state = (string) $e->getCode();
    }

    expect($state)->toBe('42501');
}

/**
 * Runs an owner statement expected to be refused by G2 (23514, given message),
 * inside a savepoint so the failure does not poison the enclosing owner probe
 * transaction.
 */
function expectP4BOwnerTriggerViolation(string $sql, array $bindings, string $messageFragment): void
{
    $exception = null;

    try {
        p4bOwner()->transaction(function () use ($sql, $bindings): void {
            p4bOwner()->statement($sql, $bindings);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('23514')
        ->and($exception->getMessage())->toContain($messageFragment);
}

/**
 * Seeds a deliverable purchase + grant INSIDE an owner-connection transaction and
 * runs $probe($owner, $grantId) against it, then rolls the whole thing back. This
 * is how the G2 trigger layer is exercised: the restricted runtime is stopped at
 * the ACL layer (42501) and a separate owner connection cannot see rows created in
 * the runtime test transaction, so the probe seeds its own visible data here.
 */
function p4bProbeG2AsOwner(Closure $probe): void
{
    $owner = p4bOwner();
    $token = str_repeat('e', 64);
    $orderNo = 'DGT-2026-'.strtoupper(bin2hex(random_bytes(5)));
    $slug = 'p4bg2-'.strtolower(bin2hex(random_bytes(4)));

    $owner->beginTransaction();
    try {
        $owner->statement('SET CONSTRAINTS ALL DEFERRED');
        $owner->statement("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES (?, 'P4B G2', 'ebook', 'published', now(), now())", [$slug]);
        $owner->statement("INSERT INTO product_files (product_id, storage_disk, storage_path, original_name, size_bytes, checksum_sha256, version, is_active) SELECT id, 'private', 'products/'||slug||'/f.zip', 'f.zip', 10, repeat('a', 64), '1.0', true FROM products WHERE slug = ?", [$slug]);
        $owner->statement("INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email, subtotal_minor, discount_minor, tax_minor, total_minor, currency, status, placed_at, expires_at, paid_at, created_at, updated_at) VALUES (gen_random_uuid(), ?, repeat('b', 64), 'g2@example.test', 1000, 0, 0, 1000, 'XOF', 'paid', now(), now() + interval '30 minutes', now(), now(), now())", [$orderNo]);
        $owner->statement("INSERT INTO order_items (order_id, product_id, product_name_snapshot, product_slug_snapshot, product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor, line_total_minor, currency, created_at, updated_at) SELECT o.id, p.id, 'P4B G2', p.slug, 'ebook', 1000, 1, 1000, 0, 1000, 'XOF', now(), now() FROM orders o, products p WHERE o.order_number = ? AND p.slug = ?", [$orderNo, $slug]);
        $owner->statement("INSERT INTO payments (public_id, order_id, provider, idempotency_key_hash, attempt_number, amount_minor, currency, status, succeeded_at, created_at, updated_at) SELECT gen_random_uuid(), o.id, 'provider_test', repeat('c', 64), 1, 1000, 'XOF', 'succeeded', now(), now(), now() FROM orders o WHERE o.order_number = ?", [$orderNo]);
        $owner->statement("INSERT INTO download_grants (public_id, order_item_id, product_file_id, token_hash, expires_at, max_downloads, created_at, updated_at) SELECT gen_random_uuid(), oi.id, pf.id, ?, now() + interval '1 day', 2, now(), now() FROM order_items oi JOIN product_files pf ON pf.product_id = oi.product_id WHERE oi.product_slug_snapshot = ?", [$token, $slug]);
        $owner->statement('SET CONSTRAINTS ALL IMMEDIATE');

        $grantId = (int) $owner->table('download_grants')->where('token_hash', $token)->value('id');

        $probe($owner, $grantId);
    } finally {
        $owner->rollBack();
    }
}

/**
 * The proven bypass, replayed by the OWNER (superuser): a temporary trigger runs
 * the counter UPDATE nested at depth 2. G2 must still refuse it, because
 * current_user is the owner, not the executor — the increment must be rolled back
 * and the counter left untouched.
 */
function expectP4BOwnerForgedIncrementRefused(Connection $owner, int $grantId, string $messageFragment): void
{
    $before = (int) $owner->table('download_grants')->where('id', $grantId)->value('downloads_count');

    $exception = null;
    try {
        // A savepoint isolates the forged attempt so its refusal leaves the outer
        // probe transaction usable and drops the temporary objects.
        $owner->transaction(function () use ($owner, $grantId): void {
            $owner->unprepared(<<<SQL
                CREATE TEMP TABLE p4b_forge (id serial primary key);
                CREATE FUNCTION pg_temp.p4b_forge_fn() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
                BEGIN
                    UPDATE public.download_grants
                    SET downloads_count = downloads_count + 1,
                        updated_at = GREATEST(clock_timestamp(), updated_at + interval '1 second')
                    WHERE id = {$grantId};
                    RETURN NEW;
                END; \$fn\$;
                CREATE TRIGGER p4b_forge_trg BEFORE INSERT ON p4b_forge FOR EACH ROW EXECUTE FUNCTION pg_temp.p4b_forge_fn();
                INSERT INTO p4b_forge DEFAULT VALUES;
                SQL);
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('23514')
        ->and($exception->getMessage())->toContain($messageFragment)
        ->and((int) $owner->table('download_grants')->where('id', $grantId)->value('downloads_count'))->toBe($before);
}

function p4bDigest(): string
{
    return hash('sha256', bin2hex(random_bytes(32)));
}

/**
 * A deliverable direct purchase (paid or partially refunded, P3C-coherent).
 *
 * @return array{order: Order, item: OrderItem, product: Product, file: ProductFile}
 */
function createP4BPurchase(OrderStatus $status = OrderStatus::Paid, ?User $user = null): array
{
    return DB::transaction(function () use ($status, $user): array {
        $product = Product::factory()->create();
        $file = ProductFile::factory()->create(['product_id' => $product->getKey()]);
        $order = Order::factory()->create([
            'status' => $status,
            'paid_at' => now(),
            'user_id' => $user?->getKey(),
        ]);
        $item = OrderItem::factory()->forOrder($order)->forProduct($product)->create();
        $payment = Payment::factory()->forOrder($order)->succeeded()->create();

        if ($status === OrderStatus::PartiallyRefunded) {
            Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => 4000]);
        }

        forceP4BConstraints();

        return compact('order', 'item', 'product', 'file');
    });
}

/**
 * @param  array<string, mixed>  $grantOverrides
 */
function createP4BGrant(array $grantOverrides = [], OrderStatus $status = OrderStatus::Paid, ?User $user = null): DownloadGrant
{
    $purchase = createP4BPurchase($status, $user);

    return DownloadGrant::factory()
        ->forOrderItem($purchase['item'])
        ->forProductFile($purchase['file'])
        ->create($grantOverrides);
}

/**
 * A fully explicit `started` insertion payload. created_at keeps its technical
 * DEFAULT unless overridden; every business value is explicit (D-029.5).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function p4bStartedPayload(DownloadGrant $grant, array $overrides = []): array
{
    return array_merge([
        'public_id' => (string) Str::uuid(),
        'download_grant_id' => $grant->getKey(),
        'status' => 'started',
        'quota_consumed' => true,
        'attempt_token_hash' => p4bDigest(),
        'attempt_expires_at' => now()->addMinutes(30),
        'denial_reason_code' => null,
        'ip_hash' => null,
        'ip_hash_key_version' => null,
        'user_agent' => null,
        'bytes_sent' => null,
        'terminal_at' => null,
        'retention_until' => now()->addDays(30),
        // Explicit application clock: PHP timestamps serialise at whole-second
        // precision, so mixing them with the PostgreSQL DEFAULT would let a
        // `terminal_at >= created_at` comparison flake across the second edge.
        'created_at' => now(),
    ], $overrides);
}

/**
 * Insert a valid `started` attempt and return the fresh row. This REALLY
 * consumes one quota unit through G5, exactly as production will.
 */
function startP4BLog(DownloadGrant $grant, array $overrides = []): object
{
    $payload = p4bStartedPayload($grant, $overrides);
    DB::table('download_logs')->insert($payload);

    return DB::table('download_logs')->where('public_id', $payload['public_id'])->first();
}

function p4bGrantCount(DownloadGrant $grant): int
{
    return (int) DB::table('download_grants')->where('id', $grant->getKey())->value('downloads_count');
}

function p4bFunctionDefinition(PDO $pdo, string $function): string
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

function runP4BMigration(string $database, string $command, string $migration): void
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

function p4bSeedDeliverablePurchase(PDO $pdo, string $slug, string $orderNumber, int $maxDownloads): void
{
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES ('{$slug}', 'P4B {$slug}', 'ebook', 'published', now(), now())");
    $pdo->exec("INSERT INTO product_files (product_id, storage_disk, storage_path, original_name, size_bytes, checksum_sha256, version) SELECT id, 'private', 'products/{$slug}/file.zip', 'file.zip', 1000, '".hash('sha256', $slug.'-file')."', '1.0' FROM products WHERE slug = '{$slug}'");
    $pdo->exec("INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email, subtotal_minor, discount_minor, tax_minor, total_minor, currency, status, placed_at, expires_at, paid_at, created_at, updated_at) VALUES (gen_random_uuid(), '{$orderNumber}', '".hash('sha256', $slug.'-order')."', '{$slug}@example.test', 1000, 0, 0, 1000, 'XOF', 'paid', now(), now() + interval '30 minutes', now(), now(), now())");
    $pdo->exec("INSERT INTO order_items (order_id, product_id, product_name_snapshot, product_slug_snapshot, product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor, line_total_minor, currency, created_at, updated_at) SELECT o.id, p.id, p.name, p.slug, p.type, 1000, 1, 1000, 0, 1000, 'XOF', now(), now() FROM orders o, products p WHERE o.order_number = '{$orderNumber}' AND p.slug = '{$slug}'");
    $pdo->exec("INSERT INTO payments (public_id, order_id, provider, idempotency_key_hash, attempt_number, amount_minor, currency, status, succeeded_at, created_at, updated_at) SELECT gen_random_uuid(), o.id, 'provider_test', '".hash('sha256', $slug.'-payment')."', 1, 1000, 'XOF', 'succeeded', now(), now(), now() FROM orders o WHERE o.order_number = '{$orderNumber}'");
    $pdo->exec("INSERT INTO download_grants (public_id, order_item_id, product_file_id, token_hash, expires_at, max_downloads, created_at, updated_at) SELECT gen_random_uuid(), oi.id, pf.id, '".hash('sha256', $slug.'-token')."', now() + interval '1 day', {$maxDownloads}, now(), now() FROM order_items oi JOIN product_files pf ON pf.product_id = oi.product_id WHERE oi.product_slug_snapshot = '{$slug}'");
    $pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
    $pdo->commit();
}

// ── 21.1 — Physical schema ───────────────────────────────────────────────────

it('applies migration 000013 with exactly fifteen columns, native types and no business default', function () {
    expect(DB::table('migrations')->where('migration', '2026_07_14_000013_create_download_logs_table')->exists())->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(29)
        ->and(Schema::hasTable('download_logs'))->toBeTrue();

    $columns = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'download_logs')
        ->pluck('is_nullable', 'column_name');

    expect($columns->keys()->sort()->values()->all())->toBe([
        'attempt_expires_at',
        'attempt_token_hash',
        'bytes_sent',
        'created_at',
        'denial_reason_code',
        'download_grant_id',
        'id',
        'ip_hash',
        'ip_hash_key_version',
        'public_id',
        'quota_consumed',
        'retention_until',
        'status',
        'terminal_at',
        'user_agent',
    ]);

    foreach (['id', 'public_id', 'download_grant_id', 'status', 'quota_consumed', 'retention_until', 'created_at'] as $required) {
        expect($columns[$required])->toBe('NO', "Column should be NOT NULL: {$required}");
    }
    foreach (['attempt_token_hash', 'attempt_expires_at', 'denial_reason_code', 'ip_hash', 'ip_hash_key_version', 'user_agent', 'bytes_sent', 'terminal_at'] as $optional) {
        expect($columns[$optional])->toBe('YES', "Column should be nullable: {$optional}");
    }

    $details = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'download_logs')
        ->get(['column_name', 'data_type', 'character_maximum_length', 'column_default'])
        ->keyBy('column_name');

    expect($details['id']->data_type)->toBe('bigint')
        ->and($details['public_id']->data_type)->toBe('uuid')
        ->and($details['download_grant_id']->data_type)->toBe('bigint')
        ->and($details['status']->data_type)->toBe('character varying')
        ->and($details['status']->character_maximum_length)->toBe(20)
        ->and($details['quota_consumed']->data_type)->toBe('boolean')
        ->and($details['attempt_token_hash']->character_maximum_length)->toBe(64)
        ->and($details['attempt_expires_at']->data_type)->toBe('timestamp with time zone')
        ->and($details['denial_reason_code']->character_maximum_length)->toBe(64)
        ->and($details['ip_hash']->character_maximum_length)->toBe(64)
        ->and($details['ip_hash_key_version']->data_type)->toBe('smallint')
        ->and($details['user_agent']->character_maximum_length)->toBe(500)
        ->and($details['bytes_sent']->data_type)->toBe('bigint')
        ->and($details['terminal_at']->data_type)->toBe('timestamp with time zone')
        ->and($details['retention_until']->data_type)->toBe('timestamp with time zone')
        ->and($details['created_at']->data_type)->toBe('timestamp with time zone');

    // The ONLY default is the technical created_at clock; no business DEFAULT
    // exists (status, quota marker, attempt window, retention are explicit).
    expect($details['created_at']->column_default)->toContain('CURRENT_TIMESTAMP');
    foreach (['status', 'quota_consumed', 'attempt_token_hash', 'attempt_expires_at', 'denial_reason_code', 'ip_hash', 'ip_hash_key_version', 'user_agent', 'bytes_sent', 'terminal_at', 'retention_until'] as $noDefault) {
        expect($details[$noDefault]->column_default)->toBeNull("Unexpected DEFAULT on: {$noDefault}");
    }

    // Forbidden columns: no raw/copied secret, no raw IP, no redundant lineage,
    // no free-form payload, no soft delete, no generic update timestamp.
    foreach ([
        'token', 'token_prefix', 'attempt_token', 'grant_token_hash', 'ip', 'ip_address',
        'email', 'order_id', 'order_item_id', 'product_file_id', 'user_id', 'storage_path',
        'checksum_sha256', 'metadata', 'payload', 'http_method', 'http_status',
        'updated_at', 'deleted_at',
    ] as $forbidden) {
        expect(Schema::hasColumn('download_logs', $forbidden))->toBeFalse("Unexpected column: {$forbidden}");
    }

    // FK: single lineage anchor, ON DELETE RESTRICT.
    $foreignKeys = DB::table('pg_constraint')
        ->whereRaw("conrelid = 'download_logs'::regclass")
        ->where('contype', 'f')
        ->pluck('confdeltype', 'conname');

    expect($foreignKeys->all())->toBe(['download_logs_download_grant_id_foreign' => 'r']);

    foreach ([
        'download_logs_public_id_unique',
        'download_logs_status_check',
        'download_logs_state_consistency_check',
        'download_logs_denial_reason_code_check',
        'download_logs_attempt_hash_format_check',
        'download_logs_attempt_window_check',
        'download_logs_terminal_timestamp_check',
        'download_logs_retention_after_created_check',
        'download_logs_ip_identity_check',
        'download_logs_user_agent_not_blank_check',
        'download_logs_bytes_sent_non_negative_check',
    ] as $constraint) {
        expect(DB::table('pg_constraint')->whereRaw("conrelid = 'download_logs'::regclass")->where('conname', $constraint)->exists())
            ->toBeTrue("Missing constraint: {$constraint}");
    }

    $indexes = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'download_logs')
        ->pluck('indexdef', 'indexname');

    expect($indexes['download_logs_attempt_token_hash_unique'])->toContain('UNIQUE')
        ->and($indexes['download_logs_attempt_token_hash_unique'])->toContain('WHERE (attempt_token_hash IS NOT NULL)')
        ->and($indexes['download_logs_grant_created_index'])->toContain('created_at DESC')
        ->and($indexes['download_logs_active_attempts_index'])->toContain("WHERE ((status)::text = 'started'::text)")
        ->and($indexes['download_logs_terminal_retention_index'])->toContain('retention_until');

    // No index predicate may depend on now(): it is not immutable.
    foreach ($indexes as $definition) {
        expect($definition)->not->toContain('now()');
    }
});

it('installs exactly the two P4-B functions and triggers and replaces G2 in place', function () {
    // G5 + G6: two new functions, two new triggers, correct timings, no deferral.
    $logTriggers = DB::table('pg_trigger')
        ->whereRaw("tgrelid = 'download_logs'::regclass")
        ->where('tgisinternal', false)
        ->get(['tgname', 'tgdeferrable']);

    expect($logTriggers)->toHaveCount(2)
        ->and($logTriggers->pluck('tgdeferrable', 'tgname')->all())->toBe([
            'download_logs_enforce_integrity_trigger' => false,
            'download_logs_retention_delete_trigger' => false,
        ]);

    $definitions = DB::table('pg_trigger as t')
        ->join('pg_proc as p', 'p.oid', '=', 't.tgfoid')
        ->whereRaw("t.tgrelid = 'download_logs'::regclass")
        ->where('t.tgisinternal', false)
        ->selectRaw('t.tgname, p.proname, pg_get_triggerdef(t.oid) AS definition')
        ->get()
        ->keyBy('tgname');

    expect($definitions['download_logs_enforce_integrity_trigger']->proname)->toBe('enforce_download_logs_integrity')
        ->and($definitions['download_logs_enforce_integrity_trigger']->definition)->toContain('BEFORE INSERT OR UPDATE')
        ->and($definitions['download_logs_retention_delete_trigger']->proname)->toBe('enforce_download_logs_retention_delete')
        ->and($definitions['download_logs_retention_delete_trigger']->definition)->toContain('BEFORE DELETE');

    // G2 is REPLACED, never duplicated: one function, same empty signature, and
    // the whole download domain counts 6 functions / 7 physical triggers.
    expect(DB::table('pg_proc')->where('proname', 'enforce_download_grants_immutability')->count())->toBe(1);

    $g2 = (string) DB::selectOne(
        "SELECT pg_get_functiondef(oid) AS definition FROM pg_proc WHERE proname = 'enforce_download_grants_immutability'",
    )->definition;

    // Post-P4-B0 authority: the increment is accepted only when the nested UPDATE
    // runs AS the download executor (non-forgeable identity), with trigger depth
    // kept as a secondary defence. The old depth-only origin check is gone.
    expect($g2)->toContain('download_grants consumption must originate from the download log executor')
        ->and($g2)->toContain("current_user = 'digitrove_download_executor'")
        ->and($g2)->toContain('pg_trigger_depth() > 1')
        ->and($g2)->not->toContain('pg_trigger_depth() <= 1')
        ->and($g2)->toContain('updated_at may only change with a valid lifecycle transition')
        ->and($g2)->toContain('updated_at must move strictly forward')
        ->and($g2)->toContain('user_fk_nullification')
        ->and($g2)->toContain('downloads_count may only increase by exactly one')
        ->and($g2)->toContain('revocation is irreversible');

    $downloadFunctions = [
        'prevent_download_grants_delete',
        'enforce_download_grants_immutability',
        'validate_download_grant_delivery',
        'validate_download_grant_order_consistency',
        'enforce_download_logs_integrity',
        'enforce_download_logs_retention_delete',
    ];
    $downloadTriggers = [
        'download_grants_prevent_delete_trigger',
        'download_grants_enforce_immutability_trigger',
        'download_grants_validate_delivery_trigger',
        'download_grants_validate_order_consistency_trigger',
        'orders_validate_download_consistency_trigger',
        'download_logs_enforce_integrity_trigger',
        'download_logs_retention_delete_trigger',
    ];

    expect(DB::table('pg_proc')->whereIn('proname', $downloadFunctions)->count())->toBe(6)
        ->and(DB::table('pg_trigger')->whereIn('tgname', $downloadTriggers)->where('tgisinternal', false)->count())->toBe(7);

    // G4 stays deferred; G0 and S1–S3 are untouched; no P5 object exists.
    expect(DB::table('pg_trigger')->where('tgname', 'download_grants_validate_order_consistency_trigger')->value('tgdeferrable'))->toBeTrue()
        ->and(DB::table('pg_proc')->where('proname', 'enforce_product_file_content_immutability')->count())->toBe(1)
        ->and(DB::table('pg_proc')->whereIn('proname', ['prevent_order_item_bundle_components_delete', 'enforce_order_item_bundle_component_immutability', 'validate_order_item_bundle_component'])->count())->toBe(3)
        ->and(Schema::hasTable('events'))->toBeFalse()
        ->and(Schema::hasTable('licenses'))->toBeFalse();
});

// ── 21.2 — Statuses and insertions ───────────────────────────────────────────

it('accepts a started insertion, a direct denial, and refuses every other insertion shape', function () {
    // Valid `started`: one row, quota consumed, secret digest present.
    $grant = createP4BGrant(['max_downloads' => 5]);
    $started = startP4BLog($grant);

    expect($started->status)->toBe('started')
        ->and((bool) $started->quota_consumed)->toBeTrue()
        ->and($started->terminal_at)->toBeNull()
        ->and($started->denial_reason_code)->toBeNull()
        ->and(p4bGrantCount($grant))->toBe(1);

    // Valid direct denial on a KNOWN grant: no secret, no consumption.
    $denied = DownloadLog::factory()->forGrant($grant)->deniedDirectly('grant_expired')->create();
    expect($denied->status)->toBe('denied')
        ->and($denied->quota_consumed)->toBeFalse()
        ->and($denied->attempt_token_hash)->toBeNull()
        ->and(p4bGrantCount($grant))->toBe(1);

    // Direct `completed` is always refused, whatever its shape.
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['status' => 'completed', 'terminal_at' => now()])),
        'download_logs completed can only result from a started attempt',
    );

    // Direct CONSUMING denial is refused: it can only come from started -> denied.
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, [
            'status' => 'denied',
            'denial_reason_code' => 'delivery_interrupted',
            'terminal_at' => now(),
        ])),
        'download_logs direct denials never consume quota',
    );

    // Unknown status: refused by the closed CHECKs (PostgreSQL evaluates them
    // in name order, so the shared prefix keeps the assertion deterministic).
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['status' => 'paused'])),
        '23514',
        'download_logs_stat',
    );

    // Status/quota/secret/reason/terminal coherence, hardened against UNKNOWN.
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['quota_consumed' => false])), '23514', 'download_logs');
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['denial_reason_code' => 'internal_error'])), '23514', 'download_logs');
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['terminal_at' => now()])), '23514', 'download_logs');
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, [
        'status' => 'denied', 'quota_consumed' => false, 'attempt_token_hash' => null, 'attempt_expires_at' => null, 'terminal_at' => now(),
    ])), '23514', 'download_logs');

    // A started row is born without bytes progression.
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['bytes_sent' => 0])),
        'download_logs are born without bytes progression',
    );

    // The technical created_at DEFAULT stands on its own — the ONLY default.
    $defaulted = p4bStartedPayload($grant);
    unset($defaulted['created_at']);
    DB::table('download_logs')->insert($defaulted);
    expect(DB::table('download_logs')->where('public_id', $defaulted['public_id'])->value('created_at'))->not->toBeNull();

    expect(p4bGrantCount($grant))->toBe(2)
        ->and(DB::table('download_logs')->count())->toBe(3);
});

// ── 21.3 — Atomic consumption ────────────────────────────────────────────────

it('pairs the started log and the exact counter increment atomically in both directions', function () {
    $grant = createP4BGrant(['max_downloads' => 3]);
    $before = DB::table('download_grants')->where('id', $grant->id)->first();

    startP4BLog($grant);

    $after = DB::table('download_grants')->where('id', $grant->id)->first();
    expect((int) $after->downloads_count)->toBe(1)
        ->and((bool) DB::selectOne('SELECT ? < updated_at AS moved FROM download_grants WHERE id = ?', [$before->updated_at, $grant->id])->moved)->toBeTrue();

    // A log refused AFTER the increment (attempt window CHECK) rolls the
    // increment back: no orphan consumption.
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, [
            'attempt_expires_at' => now()->addDays(60),
            'retention_until' => now()->addDays(30),
        ])),
        '23514',
        'download_logs_attempt_window_check',
    );
    expect(p4bGrantCount($grant))->toBe(1)
        ->and(DB::table('download_logs')->where('download_grant_id', $grant->id)->count())->toBe(1);

    // A refusal BEFORE the increment (exhausted quota) creates no log either.
    $exhausted = createP4BGrant(['max_downloads' => 1]);
    startP4BLog($exhausted);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($exhausted)),
        'download_logs cannot consume an exhausted grant quota',
    );
    expect(p4bGrantCount($exhausted))->toBe(1)
        ->and(DB::table('download_logs')->where('download_grant_id', $exhausted->id)->count())->toBe(1);

    // Two consumptions inside ONE transaction: updated_at strictly advances
    // twice even within the same wall-clock second (G5 supplies the value
    // explicitly, stepping at least one whole second — the column precision).
    $rapid = createP4BGrant(['max_downloads' => 2]);
    $timestamps = DB::transaction(function () use ($rapid): array {
        startP4BLog($rapid);
        $first = DB::table('download_grants')->where('id', $rapid->id)->value('updated_at');
        startP4BLog($rapid);
        $second = DB::table('download_grants')->where('id', $rapid->id)->value('updated_at');
        forceP4BConstraints();

        return [$first, $second];
    });

    expect(p4bGrantCount($rapid))->toBe(2)
        ->and((bool) DB::selectOne('SELECT ?::timestamptz < ?::timestamptz AS moved', $timestamps)->moved)->toBeTrue();

    // A direct denial never moves the counter and never carries a secret.
    $deniedGrant = createP4BGrant();
    DownloadLog::factory()->forGrant($deniedGrant)->deniedDirectly()->create();
    expect(p4bGrantCount($deniedGrant))->toBe(0);
});

// ── 21.4 — Hardened G2 ───────────────────────────────────────────────────────

it('refuses every direct counter write path and accepts only the nested G5 increment', function () {
    $grant = createP4BGrant(['max_downloads' => 2]);
    $originMessage = 'download_grants consumption must originate from the download log executor';

    // LAYER 1 — the restricted runtime cannot even reach the counter: raw SQL,
    // Query Builder and Eloquent are all refused at the ACL layer (42501),
    // BEFORE G2 is consulted. This is the P4-B0 boundary in force.
    expectP4BRuntimeDenied('UPDATE download_grants SET downloads_count = downloads_count + 1 WHERE id = ?', [$grant->id]);
    expectP4BRuntimeDenied('UPDATE download_grants SET downloads_count = 1 WHERE id = ?', [$grant->id]);

    // LAYER 2 — G2 itself. The runtime never reaches it, so these probes seed
    // their own grant inside an owner transaction (a separate connection cannot
    // see rows created in the runtime test transaction) and roll it back.
    p4bProbeG2AsOwner(function (Connection $owner, int $grantId) use ($originMessage): void {
        // A direct +1 at trigger depth 1 is not the executor, so the
        // non-forgeable origin check refuses it; wrong deltas keep their own
        // diagnostics because G2 validates the delta before the origin.
        expectP4BOwnerTriggerViolation('UPDATE download_grants SET downloads_count = downloads_count + 1 WHERE id = ?', [$grantId], $originMessage);
        expectP4BOwnerTriggerViolation('UPDATE download_grants SET downloads_count = 2 WHERE id = ?', [$grantId], 'download_grants downloads_count may only increase by exactly one');
        expectP4BOwnerTriggerViolation('UPDATE download_grants SET downloads_count = -1 WHERE id = ?', [$grantId], 'download_grants downloads_count may only increase by exactly one');

        // Identity, not depth — a nested trigger forged by the OWNER (a superuser)
        // reaches G2 at depth 2, but current_user is not the executor, so the
        // increment is still refused. Security no longer rests on trigger depth,
        // nor on the runtime ACLs alone.
        expectP4BOwnerForgedIncrementRefused($owner, $grantId, $originMessage);
        expect((int) $owner->table('download_grants')->where('id', $grantId)->value('downloads_count'))->toBe(0);

        // Two legitimate `started` inserts drive G5 (SECURITY DEFINER) which does
        // reach G2 as the executor, so the counter moves to the quota ceiling.
        for ($i = 0; $i < 2; $i++) {
            $owner->statement(
                "INSERT INTO download_logs (public_id, download_grant_id, status, quota_consumed, attempt_token_hash, attempt_expires_at, retention_until) VALUES (gen_random_uuid(), ?, 'started', true, ?, now() + interval '30 minutes', now() + interval '30 days')",
                [$grantId, hash('sha256', 'g2-probe-'.$i.'-'.$grantId)],
            );
        }
        expect((int) $owner->table('download_grants')->where('id', $grantId)->value('downloads_count'))->toBe(2);

        // Once exhausted, the owner's direct +1 is reported precisely: the quota
        // check precedes the origin check.
        expectP4BOwnerTriggerViolation('UPDATE download_grants SET downloads_count = 3 WHERE id = ?', [$grantId], 'download_grants quota is exhausted');
    });

    // Back on the runtime: the nested G5 increment is the only accepted path.
    expect(p4bGrantCount($grant))->toBe(0);
    startP4BLog($grant);
    startP4BLog($grant);
    expect(p4bGrantCount($grant))->toBe(2);

    // Revocation stays reachable by the runtime on its allowed columns.
    $revocable = createP4BGrant();
    expect(DB::table('download_grants')->where('id', $revocable->id)->update([
        'revoked_at' => now(),
        'revoked_reason_code' => 'support',
    ]))->toBe(1);

    // The real FK SET NULL action still nulls the buyer reference (FK actions
    // bypass the runtime column ACL).
    $buyer = User::factory()->create();
    $fkGrant = createP4BGrant([], OrderStatus::Paid, $buyer);
    expect($fkGrant->user_id)->toBe($buyer->id);
    DB::table('users')->where('id', $buyer->id)->delete();
    expect(DB::table('download_grants')->where('id', $fkGrant->id)->value('user_id'))->toBeNull();

    // Isolated updated_at falsification stays refused (runtime holds updated_at,
    // so it reaches G2, which rejects a change without a valid transition).
    expectP4BTriggerViolation(
        fn () => DB::table('download_grants')->where('id', $fkGrant->id)->update(['updated_at' => now()->addDay()]),
        'download_grants updated_at may only change with a valid lifecycle transition',
    );
});

// ── 21.5 — Grant and Order gates ─────────────────────────────────────────────

it('revalidates the order, the grant and the product file under lock before consuming', function () {
    // A partially refunded order stays deliverable.
    $partial = createP4BGrant([], OrderStatus::PartiallyRefunded);
    startP4BLog($partial);
    expect(p4bGrantCount($partial))->toBe(1);

    // An order flipped to refunded in the SAME transaction is caught by the G5
    // revalidation (G4 would refuse the active grant at commit anyway).
    $refunded = createP4BGrant();
    $refundedOrderId = $refunded->orderItem->order_id;
    expectP4BTriggerViolation(
        function () use ($refunded, $refundedOrderId): void {
            DB::table('orders')->where('id', $refundedOrderId)->update(['status' => OrderStatus::Refunded->value]);
            DB::table('download_logs')->insert(p4bStartedPayload($refunded));
        },
        'download_logs require a deliverable order',
    );
    expect(p4bGrantCount($refunded))->toBe(0);

    // A revoked grant never consumes.
    $revoked = createP4BGrant();
    DB::table('download_grants')->where('id', $revoked->id)->update(['revoked_at' => now(), 'revoked_reason_code' => 'support']);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($revoked)),
        'download_logs cannot consume a revoked grant',
    );

    // An expired grant never consumes.
    $expiredPurchase = createP4BPurchase();
    $expired = DownloadGrant::factory()->forOrderItem($expiredPurchase['item'])->forProductFile($expiredPurchase['file'])->expired()->create();
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($expired)),
        'download_logs cannot consume an expired grant',
    );

    // A deactivated product file blocks new consumption (is_active is mutable).
    $inactive = createP4BGrant();
    DB::table('product_files')->where('id', $inactive->product_file_id)->update(['is_active' => false]);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($inactive)),
        'download_logs require an active product file',
    );

    // An ABSENT product file cannot be provoked: the grant FK RESTRICT keeps the
    // delivered file alive, so the lineage can never dangle.
    expectP4BQueryException(
        fn () => DB::table('product_files')->where('id', $inactive->product_file_id)->delete(),
        '23503',
        'download_grants_product_file_id_foreign',
    );

    // An unknown grant is the FK's authority and never creates a row.
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($inactive, ['download_grant_id' => 999999999])),
        '23503',
        'download_logs_download_grant_id_foreign',
    );
});

// ── 21.6 — Attempt secret and window ─────────────────────────────────────────

it('enforces the dedicated attempt digest format, uniqueness, pairing and window', function () {
    $grant = createP4BGrant(['max_downloads' => 10]);

    // Format: exactly 64 lowercase hex characters.
    foreach ([substr(p4bDigest(), 0, 63), strtoupper(p4bDigest()), str_repeat('z', 64)] as $invalid) {
        expectP4BQueryException(
            fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['attempt_token_hash' => $invalid])),
            '23514',
            'download_logs_attempt_hash_format_check',
        );
    }

    // Uniqueness: the same digest can never authenticate two attempts, and the
    // failed duplicate rolls its increment back.
    $digest = p4bDigest();
    startP4BLog($grant, ['attempt_token_hash' => $digest]);
    $other = createP4BGrant();
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($other, ['attempt_token_hash' => $digest])),
        '23505',
        'download_logs_attempt_token_hash_unique',
    );
    expect(p4bGrantCount($other))->toBe(0);

    // Digest and expiry live together: neither exists without the other.
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['attempt_expires_at' => null])), '23514', 'download_logs');
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, [
        'status' => 'denied', 'quota_consumed' => false, 'attempt_token_hash' => null,
        'attempt_expires_at' => now()->addMinutes(30), 'denial_reason_code' => 'authorization_denied', 'terminal_at' => now(),
    ])), '23514', 'download_logs');

    // Window: strictly after birth, never beyond retention. A past expiry
    // against the technical created_at clock is refused by the same bound.
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['attempt_expires_at' => now()->subMinute()])),
        '23514',
        'download_logs_attempt_window_check',
    );
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['attempt_expires_at' => now()->addDays(60)])),
        '23514',
        'download_logs_attempt_window_check',
    );

    // The attempt digest is a DEDICATED credential: the grant token digest is
    // refused, so one leaked value can never open both doors.
    $grantDigest = DB::table('download_grants')->where('id', $grant->id)->value('token_hash');
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['attempt_token_hash' => $grantDigest])),
        'download_logs attempt digest must differ from the grant token digest',
    );

    // The raw secret never reaches the database nor the error stream.
    $rawSecret = bin2hex(random_bytes(32));
    startP4BLog($grant, ['attempt_token_hash' => hash('sha256', $rawSecret)]);
    $rows = DB::table('download_logs')->where('download_grant_id', $grant->id)->get();
    foreach ($rows as $row) {
        foreach ((array) $row as $value) {
            expect((string) $value)->not->toContain($rawSecret);
        }
    }

    $leak = null;
    try {
        DB::transaction(function () use ($grant, $rawSecret): void {
            DB::table('download_logs')->insert(p4bStartedPayload($grant, ['attempt_token_hash' => hash('sha256', $rawSecret)]));
        });
    } catch (QueryException $queryException) {
        $leak = $queryException;
    }
    expect($leak)->not->toBeNull()
        ->and($leak->getMessage())->not->toContain($rawSecret);
});

// ── 21.7 — Range and retries are structural, not new rows ────────────────────

it('binds Range and retries to one immutable attempt row without any new consumption', function () {
    $grant = createP4BGrant(['max_downloads' => 2]);
    $log = startP4BLog($grant);
    $original = DB::table('download_logs')->where('id', $log->id)->first();
    $message = 'download_logs identity, attempt secret and audit fields are immutable';

    // A retry is a LOOKUP of the same digest: re-asserting the same values
    // writes nothing new and consumes nothing.
    expect(DB::table('download_logs')->where('id', $log->id)->update(['retention_until' => $original->retention_until]))->toBe(1)
        ->and(DB::table('download_logs')->where('id', $log->id)->first())->toEqual($original)
        ->and(p4bGrantCount($grant))->toBe(1);

    // The attempt can never rotate its secret, extend its window, change its
    // grant (and therefore its ProductFile) or forge its public identity.
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $log->id)->update(['attempt_token_hash' => p4bDigest()]), $message);
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $log->id)->update(['attempt_expires_at' => now()->addDays(2)]), $message);
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $log->id)->update(['public_id' => (string) Str::uuid()]), $message);

    $foreignGrant = createP4BGrant();
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $log->id)->update(['download_grant_id' => $foreignGrant->id]), $message);

    // An EXPIRED attempt is never prolonged in place: even once its window has
    // passed, the expiry stays frozen.
    $stale = startP4BLog($grant, [
        'created_at' => now()->subHours(2),
        'attempt_expires_at' => now()->subHour(),
    ]);
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $stale->id)->update(['attempt_expires_at' => now()->addHour()]), $message);

    // A NEW attempt is a new row, a new digest and a new unit — here the quota
    // is exhausted, so the future authorisation would be refused.
    expect(p4bGrantCount($grant))->toBe(2);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant)),
        'download_logs cannot consume an exhausted grant quota',
    );
});

// ── 21.8 — HEAD and HTTP perimeter ───────────────────────────────────────────

it('keeps the gate purely relational: no HTTP surface, no HEAD artefact, no P5 object', function () {
    // No HEAD column, status or counter exists (R2A: HEAD never reaches the table).
    foreach (['http_method', 'head_count', 'is_head', 'method'] as $column) {
        expect(Schema::hasColumn('download_logs', $column))->toBeFalse("Unexpected HEAD artefact: {$column}");
    }
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload(createP4BGrant(), ['status' => 'head'])),
        '23514',
        'download_logs_stat',
    );

    // No APPLICATION download route exists. Filament and Livewire register
    // their own internal admin routes; they predate this gate and stay out of
    // its perimeter.
    foreach (Route::getRoutes() as $route) {
        $uri = mb_strtolower($route->uri());

        if (str_starts_with($uri, 'filament/') || str_starts_with($uri, 'livewire/')) {
            continue;
        }

        expect($uri)->not->toContain('download');
    }

    $appFiles = collect(File::allFiles(app_path()))->map(fn ($file) => $file->getRelativePathname());
    foreach ($appFiles as $file) {
        expect((string) $file)->not->toMatch('/DownloadController|DownloadService|IssueDownloadGrants|Stream|RateLimit/i');
    }
    // P3-D1 (D-030) legitimately introduces app/Services/Pricing, a read-only
    // COMMERCE kernel. What this gate guards is the absence of the DELIVERY
    // application layer, so the assertion is narrowed instead of dropped: no
    // listener, no job, and no service namespace related to downloads.
    expect(File::isDirectory(app_path('Listeners')))->toBeFalse()
        ->and(File::isDirectory(app_path('Jobs')))->toBeFalse();

    $serviceNamespaces = File::isDirectory(app_path('Services'))
        ? collect(File::directories(app_path('Services')))->map(fn ($path) => basename($path))
        : collect();

    foreach ($serviceNamespaces as $namespace) {
        expect($namespace)->not->toMatch('/download|delivery|grant/i');
    }
});

// ── 21.9 — Transitions ───────────────────────────────────────────────────────

it('allows only started to completed or denied, terminal once, with every field frozen', function () {
    // started -> completed: reason stays NULL, quota and secret frozen.
    $grant = createP4BGrant(['max_downloads' => 5]);
    $completed = startP4BLog($grant);
    expect(DB::table('download_logs')->where('id', $completed->id)->update([
        'status' => 'completed',
        'terminal_at' => now(),
    ]))->toBe(1);
    $completedRow = DB::table('download_logs')->where('id', $completed->id)->first();
    expect($completedRow->status)->toBe('completed')
        ->and((bool) $completedRow->quota_consumed)->toBeTrue()
        ->and($completedRow->attempt_token_hash)->toBe($completed->attempt_token_hash)
        ->and(p4bGrantCount($grant))->toBe(1);

    // started -> denied: sanitized reason required, quota NEVER given back.
    $denied = startP4BLog($grant);
    expect(p4bGrantCount($grant))->toBe(2);
    expect(DB::table('download_logs')->where('id', $denied->id)->update([
        'status' => 'denied',
        'denial_reason_code' => 'delivery_interrupted',
        'terminal_at' => now(),
    ]))->toBe(1);
    $deniedRow = DB::table('download_logs')->where('id', $denied->id)->first();
    expect((bool) $deniedRow->quota_consumed)->toBeTrue()
        ->and(p4bGrantCount($grant))->toBe(2);

    // Transition guards, one by one, on a fresh started row.
    $guarded = startP4BLog($grant);
    $guardedBefore = DB::table('download_logs')->where('id', $guarded->id)->first();

    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $guarded->id)->update(['status' => 'completed']),
        'download_logs transitions must set the terminal timestamp',
    );
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $guarded->id)->update(['status' => 'completed', 'terminal_at' => now(), 'denial_reason_code' => 'internal_error']),
        'download_logs completed never carries a denial reason',
    );
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $guarded->id)->update(['status' => 'denied', 'terminal_at' => now()]),
        'download_logs denials require a sanitized reason code',
    );
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $guarded->id)->update(['status' => 'denied', 'terminal_at' => now(), 'denial_reason_code' => 'free text!']),
        'download_logs_denial_reason_code_check',
    );
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $guarded->id)->update(['status' => 'completed', 'terminal_at' => now(), 'quota_consumed' => false]),
        'download_logs identity, attempt secret and audit fields are immutable',
    );
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $guarded->id)->update(['terminal_at' => now()]),
        'download_logs started rows cannot carry terminal fields',
    );
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $guarded->id)->update(['denial_reason_code' => 'internal_error']),
        'download_logs started rows cannot carry terminal fields',
    );
    expect(DB::table('download_logs')->where('id', $guarded->id)->first())->toEqual($guardedBefore);

    // Terminal states never reopen, re-terminalise or rewrite.
    $terminalMessage = 'download_logs terminal rows only accept a retention extension';
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $completed->id)->update(['status' => 'started']), $terminalMessage);
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $completed->id)->update(['status' => 'denied', 'denial_reason_code' => 'internal_error']), $terminalMessage);
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $completed->id)->update(['terminal_at' => now()->addHour()]), $terminalMessage);
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $deniedRow->id)->update(['denial_reason_code' => 'internal_error']), $terminalMessage);
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $deniedRow->id)->update(['status' => 'completed', 'denial_reason_code' => null]), $terminalMessage);

    // A terminal no-op assignment stays accepted; nothing is rewritten.
    $terminalBefore = DB::table('download_logs')->where('id', $completed->id)->first();
    expect(DB::table('download_logs')->where('id', $completed->id)->update(['status' => 'completed']))->toBe(1)
        ->and(DB::table('download_logs')->where('id', $completed->id)->first())->toEqual($terminalBefore);
});

// ── 21.10 + 21.11 — completed semantics and bytes_sent ───────────────────────

it('treats completed as handoff to the delivery mechanism and bytes_sent as an optional monotone measure', function () {
    // bytes_sent may stay NULL through completed: the handoff (Laravel response,
    // X-Accel, X-Sendfile, temporary URL) needs no byte count to be real.
    $grant = createP4BGrant(['max_downloads' => 5]);
    $nullBytes = startP4BLog($grant);
    expect(DB::table('download_logs')->where('id', $nullBytes->id)->update(['status' => 'completed', 'terminal_at' => now()]))->toBe(1)
        ->and(DB::table('download_logs')->where('id', $nullBytes->id)->value('bytes_sent'))->toBeNull();

    // bytes_sent below product_files.size_bytes never blocks completed: the log
    // never claims the client received the whole file.
    $sizeBytes = (int) DB::table('product_files')->where('id', $grant->product_file_id)->value('size_bytes');
    $partialBytes = startP4BLog($grant);
    expect(DB::table('download_logs')->where('id', $partialBytes->id)->update(['bytes_sent' => max(0, $sizeBytes - 1)]))->toBe(1)
        ->and(DB::table('download_logs')->where('id', $partialBytes->id)->update(['status' => 'completed', 'terminal_at' => now()]))->toBe(1);

    // Monotone progression during started: 0 accepted, forward accepted,
    // backwards / reset / negative refused, and no progression consumes quota.
    $progress = startP4BLog($grant);
    $countBefore = p4bGrantCount($grant);
    // A negative value from the pristine NULL state passes the monotone gate on
    // purpose and lands on the structural CHECK: defence in depth, both layers.
    expectP4BQueryException(
        fn () => DB::table('download_logs')->where('id', $progress->id)->update(['bytes_sent' => -1]),
        '23514',
        'download_logs_bytes_sent_non_negative_check',
    );
    expect(DB::table('download_logs')->where('id', $progress->id)->update(['bytes_sent' => 0]))->toBe(1)
        ->and(DB::table('download_logs')->where('id', $progress->id)->update(['bytes_sent' => 100]))->toBe(1);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $progress->id)->update(['bytes_sent' => 50]),
        'download_logs bytes_sent may only progress forward',
    );
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $progress->id)->update(['bytes_sent' => null]),
        'download_logs bytes_sent may only progress forward',
    );
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $progress->id)->update(['bytes_sent' => -5]),
        'download_logs bytes_sent may only progress forward',
    );
    expect(p4bGrantCount($grant))->toBe($countBefore);

    // The terminal value freezes with the row.
    expect(DB::table('download_logs')->where('id', $progress->id)->update(['status' => 'completed', 'terminal_at' => now(), 'bytes_sent' => 200]))->toBe(1);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $progress->id)->update(['bytes_sent' => 300]),
        'download_logs terminal rows only accept a retention extension',
    );
});

// ── 21.12 — Versioned IP HMAC and bounded user agent ─────────────────────────

it('stores only a versioned IP HMAC pseudonym and a bounded non-blank user agent', function () {
    $grant = createP4BGrant(['max_downloads' => 10]);

    // Both absent is legitimate; both present is legitimate.
    startP4BLog($grant);
    $hmac = hash_hmac('sha256', '203.0.113.10', bin2hex(random_bytes(16)));
    $identified = startP4BLog($grant, ['ip_hash' => $hmac, 'ip_hash_key_version' => 3, 'user_agent' => str_repeat('u', 500)]);
    expect($identified->ip_hash)->toBe($hmac)
        ->and((int) $identified->ip_hash_key_version)->toBe(3);

    // Hash and version live together, versions start at 1, format is lowercase hex.
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['ip_hash' => $hmac])), '23514', 'download_logs_ip_identity_check');
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['ip_hash_key_version' => 1])), '23514', 'download_logs_ip_identity_check');
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['ip_hash' => $hmac, 'ip_hash_key_version' => 0])), '23514', 'download_logs_ip_identity_check');
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['ip_hash' => $hmac, 'ip_hash_key_version' => -2])), '23514', 'download_logs_ip_identity_check');
    expectP4BQueryException(fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['ip_hash' => strtoupper($hmac), 'ip_hash_key_version' => 1])), '23514', 'download_logs_ip_identity_check');

    // The network pseudonym is immutable: no rewrite, no re-keying in place.
    $immutable = 'download_logs identity, attempt secret and audit fields are immutable';
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $identified->id)->update(['ip_hash' => hash_hmac('sha256', '203.0.113.10', 'other-key')]), $immutable);
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $identified->id)->update(['ip_hash_key_version' => 4]), $immutable);
    expectP4BTriggerViolation(fn () => DB::table('download_logs')->where('id', $identified->id)->update(['user_agent' => 'rewritten']), $immutable);

    // The user agent is bounded to 500 characters and never blank.
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['user_agent' => str_repeat('u', 501)])),
        '22001',
        'character varying(500)',
    );
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['user_agent' => " \t\n"])),
        '23514',
        'download_logs_user_agent_not_blank_check',
    );

    // The raw IP never appears in any stored value.
    foreach (DB::table('download_logs')->where('download_grant_id', $grant->id)->get() as $row) {
        foreach ((array) $row as $value) {
            expect((string) $value)->not->toContain('203.0.113.10');
        }
    }
});

// ── 21.13 — Retention ────────────────────────────────────────────────────────

it('demands an explicit retention that can only ever be extended', function () {
    $grant = createP4BGrant(['max_downloads' => 10]);

    // Mandatory and explicit: no DEFAULT exists to fall back on.
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['retention_until' => null])),
        '23502',
        'retention_until',
    );
    expect(DB::table('information_schema.columns')->where('table_name', 'download_logs')->where('column_name', 'retention_until')->value('column_default'))->toBeNull();

    // Strictly after birth.
    expectP4BQueryException(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($grant, ['retention_until' => now()->subDay(), 'attempt_expires_at' => now()->addMinute()])),
        '23514',
        'download_logs',
    );

    // Reduction refused, identical value accepted, extension accepted — on
    // started AND on terminal rows.
    $log = startP4BLog($grant);
    $retention = DB::table('download_logs')->where('id', $log->id)->value('retention_until');
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $log->id)->update(['retention_until' => now()->addDay()]),
        'download_logs retention may only be extended',
    );
    expect(DB::table('download_logs')->where('id', $log->id)->update(['retention_until' => $retention]))->toBe(1)
        ->and(DB::table('download_logs')->where('id', $log->id)->update(['retention_until' => now()->addDays(60)]))->toBe(1);

    DB::table('download_logs')->where('id', $log->id)->update(['status' => 'completed', 'terminal_at' => now()]);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $log->id)->update(['retention_until' => now()->addDays(10)]),
        'download_logs retention may only be extended',
    );
    expect(DB::table('download_logs')->where('id', $log->id)->update(['retention_until' => now()->addDays(90)]))->toBe(1);
});

// ── 21.14 — G6 controlled purge ──────────────────────────────────────────────

it('purges only terminal rows past retention, atomically, without touching the grant', function () {
    $grant = createP4BGrant(['max_downloads' => 10]);

    // An active attempt is never purgeable, through SQL or Eloquent.
    $active = startP4BLog($grant);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $active->id)->delete(),
        'download_logs may only be purged once terminal',
    );
    expectP4BTriggerViolation(
        fn () => DownloadLog::query()->whereKey($active->id)->first()->delete(),
        'download_logs may only be purged once terminal',
    );

    // A terminal row before its retention is never purgeable.
    DB::table('download_logs')->where('id', $active->id)->update(['status' => 'completed', 'terminal_at' => now()]);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->where('id', $active->id)->delete(),
        'download_logs may only be purged after retention expires',
    );

    // Terminal rows past retention become purgeable — completed AND denied.
    $purgeableCompleted = startP4BLog($grant, [
        'created_at' => now()->subDays(40),
        'attempt_expires_at' => now()->subDays(40)->addMinutes(30),
        'retention_until' => now()->subDays(5),
    ]);
    DB::table('download_logs')->where('id', $purgeableCompleted->id)->update(['status' => 'completed', 'terminal_at' => now()->subDays(39)]);

    $purgeableDenied = DownloadLog::factory()->forGrant($grant)->deniedDirectly()->create([
        'created_at' => now()->subDays(40),
        'terminal_at' => now()->subDays(40)->addMinute(),
        'retention_until' => now()->subDays(5),
    ]);

    $countBefore = p4bGrantCount($grant);

    // A mixed multi-row DELETE fails atomically: nothing at all is deleted.
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->whereIn('id', [$purgeableCompleted->id, $active->id])->delete(),
        'download_logs',
    );
    expect(DB::table('download_logs')->whereIn('id', [$purgeableCompleted->id, $active->id])->count())->toBe(2);

    // Eligible purges succeed and delete ONLY the log rows.
    expect(DB::table('download_logs')->where('id', $purgeableCompleted->id)->delete())->toBe(1)
        ->and(DownloadLog::query()->whereKey($purgeableDenied->getKey())->first()->delete())->toBeTrue();

    // The purge never decrements the counter, never returns quota, never
    // touches the grant, the order item or the product file.
    expect(p4bGrantCount($grant))->toBe($countBefore)
        ->and(DB::table('download_grants')->where('id', $grant->id)->exists())->toBeTrue()
        ->and(DB::table('product_files')->where('id', $grant->product_file_id)->exists())->toBeTrue()
        ->and(DB::table('order_items')->where('id', $grant->order_item_id)->exists())->toBeTrue();

    // Proof that purging returns NO quota: an exhausted grant stays exhausted
    // after its consuming log has been purged.
    $exhausted = createP4BGrant(['max_downloads' => 1]);
    $consuming = startP4BLog($exhausted, [
        'created_at' => now()->subDays(40),
        'attempt_expires_at' => now()->subDays(40)->addMinutes(30),
        'retention_until' => now()->subDays(5),
    ]);
    DB::table('download_logs')->where('id', $consuming->id)->update(['status' => 'completed', 'terminal_at' => now()->subDays(39)]);
    expect(DB::table('download_logs')->where('id', $consuming->id)->delete())->toBe(1)
        ->and(p4bGrantCount($exhausted))->toBe(1);
    expectP4BTriggerViolation(
        fn () => DB::table('download_logs')->insert(p4bStartedPayload($exhausted)),
        'download_logs cannot consume an exhausted grant quota',
    );
});

// ── 21.16 — Deletions and FK protection ──────────────────────────────────────

it('keeps the grant lineage undeletable while logs remain the only purgeable table', function () {
    $grant = createP4BGrant(['max_downloads' => 5]);
    startP4BLog($grant);

    // The runtime cannot delete a grant at all (no DELETE privilege → 42501), and
    // behind that ACL G1 still refuses grant deletion for the owner too (23514) —
    // the log FK RESTRICT stands behind both. The owner probe seeds its own
    // visible grant, since the runtime test transaction is invisible to it.
    expectP4BRuntimeDenied('DELETE FROM download_grants WHERE id = ?', [$grant->id]);
    p4bProbeG2AsOwner(function (Connection $owner, int $grantId): void {
        expectP4BOwnerTriggerViolation('DELETE FROM download_grants WHERE id = ?', [$grantId], 'download_grants are revoked, never deleted');
    });

    // The delivered file stays protected through the grant chain.
    expectP4BQueryException(
        fn () => DB::table('product_files')->where('id', $grant->product_file_id)->delete(),
        '23503',
        'download_grants_product_file_id_foreign',
    );

    // No CASCADE reaches download_logs from anywhere.
    expect((int) DB::table('pg_constraint')->whereRaw("conrelid = 'download_logs'::regclass")->where('contype', 'f')->where('confdeltype', '<>', 'r')->count())->toBe(0)
        ->and(DB::table('download_logs')->where('download_grant_id', $grant->id)->count())->toBe(1);
});

// ── 21.15 — Concurrency with two real connections ────────────────────────────

it('serialises concurrent consumption on the last unit and leaves distinct orders lock-free', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4b_concurrency_'.strtolower(Str::random(10)));
    $connection = config('database.connections.pgsql');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName());

    $first = null;
    $second = null;

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000013_create_download_logs_table.php');

        $seed = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Grant A: a single unit (the contended resource). Grants C and D: room.
        p4bSeedDeliverablePurchase($seed, 'p4b-conc-a', 'DGT-2026-P4B0000001', 1);
        p4bSeedDeliverablePurchase($seed, 'p4b-conc-c', 'DGT-2026-P4B0000003', 5);
        p4bSeedDeliverablePurchase($seed, 'p4b-conc-d', 'DGT-2026-P4B0000004', 5);
        $seed = null;

        $first = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $second = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $second->exec("SET lock_timeout = '1000ms'");

        $insertStarted = static fn (string $slug, string $digest): string => "INSERT INTO download_logs (public_id, download_grant_id, status, quota_consumed, attempt_token_hash, attempt_expires_at, retention_until) SELECT gen_random_uuid(), dg.id, 'started', true, '{$digest}', now() + interval '30 minutes', now() + interval '30 days' FROM download_grants dg JOIN order_items oi ON oi.id = dg.order_item_id WHERE oi.product_slug_snapshot = '{$slug}'";

        // (a) Last unit: the second consumer blocks on the G5 lock (55P03 with a
        // timeout), then fails on the exhausted quota after the first commits.
        $first->beginTransaction();
        $first->exec($insertStarted('p4b-conc-a', hash('sha256', 'p4b-a-1')));

        $blocked = null;
        $second->beginTransaction();
        try {
            $second->exec($insertStarted('p4b-conc-a', hash('sha256', 'p4b-a-2')));
        } catch (PDOException $pdoException) {
            $blocked = $pdoException;
        }
        expect($blocked)->not->toBeNull()
            ->and($blocked->getCode())->toBe('55P03');
        $second->rollBack();
        $first->commit();

        $exhausted = null;
        try {
            $second->exec($insertStarted('p4b-conc-a', hash('sha256', 'p4b-a-3')));
        } catch (PDOException $pdoException) {
            $exhausted = $pdoException;
        }
        expect($exhausted)->not->toBeNull()
            ->and($exhausted->getCode())->toBe('23514')
            ->and($exhausted->getMessage())->toContain('download_logs cannot consume an exhausted grant quota');

        $countA = static fn (PDO $pdo): array => $pdo->query("SELECT dg.downloads_count::int AS consumed, (SELECT COUNT(*)::int FROM download_logs dl WHERE dl.download_grant_id = dg.id) AS logs FROM download_grants dg JOIN order_items oi ON oi.id = dg.order_item_id WHERE oi.product_slug_snapshot = 'p4b-conc-a'")->fetch(PDO::FETCH_ASSOC);
        expect($countA($first))->toBe(['consumed' => 1, 'logs' => 1]);

        // (b) Consumption vs revocation: the revoker waits behind the G5 grant
        // lock, revokes cleanly afterwards, and no later consumption sneaks in.
        $first->beginTransaction();
        $first->exec($insertStarted('p4b-conc-c', hash('sha256', 'p4b-c-1')));

        $revokeBlocked = null;
        $second->beginTransaction();
        try {
            $second->exec("UPDATE download_grants SET revoked_at = now(), revoked_reason_code = 'support' WHERE id = (SELECT dg.id FROM download_grants dg JOIN order_items oi ON oi.id = dg.order_item_id WHERE oi.product_slug_snapshot = 'p4b-conc-c')");
        } catch (PDOException $pdoException) {
            $revokeBlocked = $pdoException;
        }
        expect($revokeBlocked)->not->toBeNull()
            ->and($revokeBlocked->getCode())->toBe('55P03');
        $second->rollBack();
        $first->commit();

        $second->exec("UPDATE download_grants SET revoked_at = now(), revoked_reason_code = 'support' WHERE id = (SELECT dg.id FROM download_grants dg JOIN order_items oi ON oi.id = dg.order_item_id WHERE oi.product_slug_snapshot = 'p4b-conc-c')");
        $postRevocation = null;
        try {
            $second->exec($insertStarted('p4b-conc-c', hash('sha256', 'p4b-c-2')));
        } catch (PDOException $pdoException) {
            $postRevocation = $pdoException;
        }
        expect($postRevocation)->not->toBeNull()
            ->and($postRevocation->getCode())->toBe('23514')
            ->and($postRevocation->getMessage())->toContain('download_logs cannot consume a revoked grant');

        // (c) Consumption vs total refund: a refund flow holding the ORDER lock
        // blocks G5 (Order first, then Grant — no crossed locks, no deadlock).
        $first->beginTransaction();
        $first->exec("SELECT id FROM orders WHERE order_number = 'DGT-2026-P4B0000004' FOR UPDATE");

        $refundBlocked = null;
        $second->beginTransaction();
        try {
            $second->exec($insertStarted('p4b-conc-d', hash('sha256', 'p4b-d-1')));
        } catch (PDOException $pdoException) {
            $refundBlocked = $pdoException;
        }
        expect($refundBlocked)->not->toBeNull()
            ->and($refundBlocked->getCode())->toBe('55P03');
        $second->rollBack();
        $first->rollBack();

        // (d) Distinct orders never share a lock: while one consumption holds
        // grant C's order... (C is revoked, use A's order via a fresh D insert)
        $first->beginTransaction();
        $first->exec("SELECT id FROM orders WHERE order_number = 'DGT-2026-P4B0000003' FOR UPDATE");
        $second->exec($insertStarted('p4b-conc-d', hash('sha256', 'p4b-d-2')));
        $first->rollBack();

        $countD = $first->query("SELECT dg.downloads_count::int FROM download_grants dg JOIN order_items oi ON oi.id = dg.order_item_id WHERE oi.product_slug_snapshot = 'p4b-conc-d'")->fetchColumn();
        expect((int) $countD)->toBe(1);

        // (e) Concurrent digest collision: the second writer waits on the unique
        // index (55P03 under timeout), then collides in 23505 — and its rolled
        // back increment leaves the counter exact.
        $first->beginTransaction();
        $first->exec($insertStarted('p4b-conc-d', hash('sha256', 'p4b-collision')));

        $collisionWait = null;
        $second->beginTransaction();
        try {
            $second->exec($insertStarted('p4b-conc-d', hash('sha256', 'p4b-collision')));
        } catch (PDOException $pdoException) {
            $collisionWait = $pdoException;
        }
        expect($collisionWait)->not->toBeNull()
            ->and($collisionWait->getCode())->toBe('55P03');
        $second->rollBack();
        $first->commit();

        $collision = null;
        try {
            $second->exec($insertStarted('p4b-conc-d', hash('sha256', 'p4b-collision')));
        } catch (PDOException $pdoException) {
            $collision = $pdoException;
        }
        expect($collision)->not->toBeNull()
            ->and($collision->getCode())->toBe('23505')
            ->and($collision->getMessage())->toContain('download_logs_attempt_token_hash_unique');

        $finalD = $first->query("SELECT dg.downloads_count::int AS consumed, (SELECT COUNT(*)::int FROM download_logs dl WHERE dl.download_grant_id = dg.id) AS logs FROM download_grants dg JOIN order_items oi ON oi.id = dg.order_item_id WHERE oi.product_slug_snapshot = 'p4b-conc-d'")->fetch(PDO::FETCH_ASSOC);
        expect($finalD)->toBe(['consumed' => 2, 'logs' => 2]);
    } finally {
        $first = null;
        $second = null;
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

// ── 22.1 — Isolated rollback with an empty table ─────────────────────────────

it('rolls back an empty 000013 alone, restores the exact post-000012 G2 and keeps P4-B0 in force', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4b_rollback_'.strtolower(Str::random(10)));
    $gate = '2026_07_14_000013_create_download_logs_table.php';
    $grantFunctions = [
        'prevent_download_grants_delete',
        'enforce_download_grants_immutability',
        'validate_download_grant_delivery',
        'validate_download_grant_order_consistency',
    ];
    $grantTriggers = [
        'download_grants_prevent_delete_trigger',
        'download_grants_enforce_immutability_trigger',
        'download_grants_validate_delivery_trigger',
        'download_grants_validate_order_consistency_trigger',
        'orders_validate_download_consistency_trigger',
    ];
    $logFunctions = ['enforce_download_logs_integrity', 'enforce_download_logs_retention_delete'];
    $logTriggers = ['download_logs_enforce_integrity_trigger', 'download_logs_retention_delete_trigger'];
    $pdo = null;

    try {
        $harness->create();
        // The boundary must be in force BEFORE 000013: it stops at 000012 (P4-B0),
        // which is exactly the state 000013's fail-closed preconditions require and
        // the state its down() must restore.
        $applied = $harness->applyMigrationsThrough('2026_07_14_000012_harden_database_runtime_privileges.php');
        expect(end($applied))->toBe('2026_07_14_000012_harden_database_runtime_privileges');

        $connection = config('database.connections.pgsql');
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName()),
            $connection['username'],
            $connection['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $hardenedG2 = p4bFunctionDefinition($pdo, 'enforce_download_grants_immutability');
        $g3Definition = p4bFunctionDefinition($pdo, 'validate_download_grant_delivery');

        runP4BMigration($harness->databaseName(), 'migrate', $gate);

        $p4bG2 = p4bFunctionDefinition($pdo, 'enforce_download_grants_immutability');
        expect($harness->hasTable('download_logs'))->toBeTrue()
            ->and($harness->countFunctions($logFunctions))->toBe(2)
            ->and($harness->countTriggers($logTriggers))->toBe(2)
            ->and($p4bG2)->not->toBe($hardenedG2)
            ->and($p4bG2)->toContain('download_grants consumption must originate from the download log executor');

        // No log row is ever created: this is the EMPTY-table rollback path.
        $downed = $harness->rollbackExactMigrations([$gate]);

        expect($downed)->toBe(['2026_07_14_000013_create_download_logs_table'])
            ->and($harness->hasTable('download_logs'))->toBeFalse()
            ->and($harness->countFunctions($logFunctions))->toBe(0)
            ->and($harness->countTriggers($logTriggers))->toBe(0)
            // G2 comes back BYTE-EXACT to its post-000012 state; G3 untouched.
            ->and(p4bFunctionDefinition($pdo, 'enforce_download_grants_immutability'))->toBe($hardenedG2)
            ->and(p4bFunctionDefinition($pdo, 'validate_download_grant_delivery'))->toBe($g3Definition)
            ->and($harness->countFunctions($grantFunctions))->toBe(4)
            ->and($harness->countTriggers($grantTriggers))->toBe(5)
            ->and($harness->hasTable('download_grants'))->toBeTrue()
            ->and($harness->hasTable('order_item_bundle_components'))->toBeTrue()
            ->and($harness->countFunctions(['enforce_product_file_content_immutability']))->toBe(1)
            ->and($harness->countFunctions(['prevent_order_item_bundle_components_delete', 'enforce_order_item_bundle_component_immutability', 'validate_order_item_bundle_component']))->toBe(3)
            ->and($harness->hasTable('events'))->toBeFalse();

        // P4-B0 survives the P4-B rollback: 000012 stays applied.
        $remaining = $harness->ranMigrations();
        expect(end($remaining))->toBe('2026_07_14_000012_harden_database_runtime_privileges')
            ->and($remaining)->toContain('2026_07_14_000012_harden_database_runtime_privileges')
            ->and($remaining)->not->toContain('2026_07_14_000013_create_download_logs_table');

        $pdo = null;
    } finally {
        $pdo = null;
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

// ── 22.2 — Fail-closed rollback with audit rows present ──────────────────────

it('refuses to roll back 000013 while audit rows exist and destroys nothing', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4b_occupied_'.strtolower(Str::random(10)));
    $gate = '2026_07_14_000013_create_download_logs_table.php';
    $pdo = null;

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($gate);
        expect(end($applied))->toBe('2026_07_14_000013_create_download_logs_table');

        $connection = config('database.connections.pgsql');
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName()),
            $connection['username'],
            $connection['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        // One REAL consumed attempt: G5 increments the counter with the log.
        p4bSeedDeliverablePurchase($pdo, 'p4b-occupied', 'DGT-2026-P4B0000009', 5);
        $pdo->exec("INSERT INTO download_logs (public_id, download_grant_id, status, quota_consumed, attempt_token_hash, attempt_expires_at, retention_until) SELECT gen_random_uuid(), dg.id, 'started', true, '".hash('sha256', 'p4b-occupied-attempt')."', now() + interval '30 minutes', now() + interval '30 days' FROM download_grants dg");
        expect((int) $pdo->query('SELECT downloads_count FROM download_grants')->fetchColumn())->toBe(1);

        // The rollback is refused in 23514 BEFORE touching any object.
        $refusal = null;
        try {
            $harness->rollbackExactMigrations([$gate]);
        } catch (RuntimeException $runtimeException) {
            $refusal = $runtimeException;
        }
        // The console may wrap the long message: assert its stable prefix.
        expect($refusal)->not->toBeNull()
            ->and($refusal->getMessage())->toContain('download_logs rollback refused');

        // Nothing was dropped, restored, decremented or unrecorded.
        expect($harness->hasTable('download_logs'))->toBeTrue()
            ->and((int) $pdo->query('SELECT COUNT(*) FROM download_logs')->fetchColumn())->toBe(1)
            ->and($harness->countFunctions(['enforce_download_logs_integrity', 'enforce_download_logs_retention_delete']))->toBe(2)
            ->and($harness->countTriggers(['download_logs_enforce_integrity_trigger', 'download_logs_retention_delete_trigger']))->toBe(2)
            ->and(p4bFunctionDefinition($pdo, 'enforce_download_grants_immutability'))->toContain('download_grants consumption must originate from the download log executor')
            ->and((int) $pdo->query('SELECT downloads_count FROM download_grants')->fetchColumn())->toBe(1)
            ->and($harness->ranMigrations())->toContain('2026_07_14_000013_create_download_logs_table');

        $pdo = null;
    } finally {
        $pdo = null;
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

// ── Model, factory and relations ─────────────────────────────────────────────

it('exposes a minimal audit model that hides digests and consumes honestly through its factory', function () {
    // The default factory state builds a REAL consumed attempt through G5.
    $log = DownloadLog::factory()->create();
    $grant = $log->downloadGrant;

    expect($log->status)->toBe('started')
        ->and($log->quota_consumed)->toBeTrue()
        ->and($grant)->not->toBeNull()
        ->and($grant->downloads_count)->toBe(1)
        ->and($grant->downloadLogs()->count())->toBe(1)
        ->and($grant->downloadLogs()->first()->is($log))->toBeTrue();

    // Digests never leak through serialisation; no generic update timestamp exists.
    $serialised = $log->fresh()->toArray();
    expect($serialised)->not->toHaveKey('attempt_token_hash')
        ->and($serialised)->not->toHaveKey('ip_hash')
        ->and($serialised)->not->toHaveKey('updated_at')
        ->and(DownloadLog::UPDATED_AT)->toBeNull();

    // The model DECLARES no secret-generating, token-validating, file-serving
    // or quota-mutating helper: the database remains the final authority.
    $modelReflection = new ReflectionClass(DownloadLog::class);
    foreach ($modelReflection->getMethods() as $method) {
        if ($method->getFileName() !== $modelReflection->getFileName()) {
            continue;
        }

        expect(mb_strtolower($method->getName()))->not->toMatch('/token|secret|stream|serve|consume|deliver/');
    }
});
