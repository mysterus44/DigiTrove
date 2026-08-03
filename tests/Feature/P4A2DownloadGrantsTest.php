<?php

use App\Enums\OrderStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemBundleComponent;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesDatabaseAsOwner as RefreshDatabase;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

function forceP4A2Constraints(): void
{
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
}

function expectP4A2QueryException(Closure $callback, string $sqlState, string $messageFragment): void
{
    $exception = null;

    try {
        DB::transaction(function () use ($callback): void {
            $callback();
            forceP4A2Constraints();
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

function expectP4A2TriggerViolation(Closure $callback, string $messageFragment): void
{
    expectP4A2QueryException($callback, '23514', $messageFragment);
}

function p4a2TokenHash(): string
{
    return hash('sha256', bin2hex(random_bytes(32)));
}

/**
 * A deliverable direct purchase.
 *
 * @return array{order: Order, item: OrderItem, product: Product, file: ProductFile}
 */
function createP4A2DirectPurchase(OrderStatus $status = OrderStatus::Paid, ?User $user = null): array
{
    return DB::transaction(function () use ($status, $user): array {
        $product = Product::factory()->create();
        $file = ProductFile::factory()->create(['product_id' => $product->getKey()]);

        $order = Order::factory()->create([
            'status' => $status,
            'paid_at' => in_array($status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true) ? now() : null,
            'user_id' => $user?->getKey(),
        ]);
        $item = OrderItem::factory()->forOrder($order)->forProduct($product)->create();

        // The P3C deferred matrix demands a coherent payment for each order status.
        if (in_array($status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true)) {
            $payment = Payment::factory()->forOrder($order)->succeeded()->create();

            if ($status === OrderStatus::PartiallyRefunded) {
                Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => 4000]);
            } elseif ($status === OrderStatus::Refunded) {
                Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => $payment->amount_minor]);
            }
        } elseif ($status === OrderStatus::PaymentReview) {
            Payment::factory()->forOrder($order)->requiresReview()->create();
        }

        forceP4A2Constraints();

        return compact('order', 'item', 'product', 'file');
    });
}

/**
 * A deliverable bundle purchase with a snapshotted component that owns a file.
 *
 * @return array{order: Order, item: OrderItem, bundle: Product, component: Product, componentFile: ProductFile, bundleFile: ProductFile}
 */
function createP4A2BundlePurchase(bool $withSnapshot = true): array
{
    return DB::transaction(function () use ($withSnapshot): array {
        $bundle = Product::factory()->bundle()->create();
        $bundleFile = ProductFile::factory()->create(['product_id' => $bundle->getKey()]);
        $component = Product::factory()->create();
        $componentFile = ProductFile::factory()->create(['product_id' => $component->getKey()]);
        $bundle->childProducts()->attach($component->getKey(), ['position' => 0]);

        $order = Order::factory()->paid()->create();
        $item = OrderItem::factory()->forOrder($order)->forProduct($bundle)->create();
        Payment::factory()->forOrder($order)->succeeded()->create();

        if ($withSnapshot) {
            OrderItemBundleComponent::factory()->forOrderItem($item)->forComponent($component)->create();
        }

        forceP4A2Constraints();

        return compact('order', 'item', 'bundle', 'component', 'componentFile', 'bundleFile');
    });
}

it('applies migration 000010 with the exact schema, four functions and five triggers', function () {
    expect(DB::table('migrations')->where('migration', '2026_07_14_000010_create_download_grants_table')->exists())->toBeTrue()
        ->and(DB::table('migrations')->count())->toBe(36)
        ->and(Schema::hasTable('download_grants'))->toBeTrue();

    $columns = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'download_grants')
        ->pluck('is_nullable', 'column_name');

    expect($columns->keys()->sort()->values()->all())->toBe([
        'created_at',
        'downloads_count',
        'expires_at',
        'id',
        'max_downloads',
        'order_item_id',
        'product_file_id',
        'public_id',
        'revoked_at',
        'revoked_reason_code',
        'token_hash',
        'updated_at',
        'user_id',
    ]);

    $types = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'download_grants')
        ->pluck('data_type', 'column_name');

    expect($types['public_id'])->toBe('uuid')
        ->and($types['order_item_id'])->toBe('bigint')
        ->and($types['product_file_id'])->toBe('bigint')
        ->and($types['user_id'])->toBe('bigint')
        ->and($types['token_hash'])->toBe('character varying')
        ->and($types['max_downloads'])->toBe('bigint')
        ->and($types['downloads_count'])->toBe('bigint')
        ->and($types['expires_at'])->toBe('timestamp with time zone')
        ->and($types['revoked_at'])->toBe('timestamp with time zone')
        // Quota and expiry are mandatory: no unlimited quota, no absent expiry.
        ->and($columns['max_downloads'])->toBe('NO')
        ->and($columns['expires_at'])->toBe('NO')
        ->and($columns['downloads_count'])->toBe('NO')
        ->and($columns['user_id'])->toBe('YES')
        ->and($columns['revoked_at'])->toBe('YES');

    // No commercial DEFAULT on quota/expiry; downloads_count keeps a technical 0.
    $defaults = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'download_grants')
        ->pluck('column_default', 'column_name');

    expect($defaults['max_downloads'])->toBeNull()
        ->and($defaults['expires_at'])->toBeNull()
        ->and($defaults['downloads_count'])->toBe("'0'::bigint");

    // Nothing that belongs to P4-B or that would duplicate ProductFile.
    foreach (['ip_hash', 'user_agent', 'metadata', 'last_downloaded_at', 'token_prefix', 'token', 'status', 'deleted_at', 'checksum_sha256', 'storage_path'] as $forbidden) {
        expect(Schema::hasColumn('download_grants', $forbidden))->toBeFalse("Unexpected column: {$forbidden}");
    }

    $foreignKeys = DB::table('pg_constraint')
        ->whereRaw("conrelid = 'download_grants'::regclass")
        ->where('contype', 'f')
        ->pluck('confdeltype', 'conname');

    expect($foreignKeys['download_grants_order_item_id_foreign'])->toBe('r')
        ->and($foreignKeys['download_grants_product_file_id_foreign'])->toBe('r')
        ->and($foreignKeys['download_grants_user_id_foreign'])->toBe('n');

    foreach ([
        'download_grants_public_id_unique',
        'download_grants_token_hash_unique',
        'download_grants_token_hash_format_check',
        'download_grants_expires_after_created_check',
        'download_grants_max_downloads_positive_check',
        'download_grants_count_within_quota_check',
        'download_grants_revocation_pair_check',
    ] as $constraint) {
        expect(DB::table('pg_constraint')->whereRaw("conrelid = 'download_grants'::regclass")->where('conname', $constraint)->exists())
            ->toBeTrue("Missing constraint: {$constraint}");
    }

    $indexes = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'download_grants')
        ->pluck('indexdef', 'indexname');

    expect($indexes['download_grants_active_pair_unique'])->toContain('UNIQUE')
        ->and($indexes['download_grants_active_pair_unique'])->toContain('WHERE (revoked_at IS NULL)')
        ->and($indexes)->toHaveKey('download_grants_order_item_id_index')
        ->and($indexes)->toHaveKey('download_grants_product_file_id_index')
        ->and($indexes)->toHaveKey('download_grants_user_id_index')
        ->and($indexes)->toHaveKey('download_grants_active_expiry_index');

    // No index predicate may depend on now(): it is not immutable (D-029.4).
    foreach ($indexes as $definition) {
        expect($definition)->not->toContain('now()');
    }

    $functions = ['prevent_download_grants_delete', 'enforce_download_grants_immutability', 'validate_download_grant_delivery', 'validate_download_grant_order_consistency'];
    expect(DB::table('pg_proc')->whereIn('proname', $functions)->count())->toBe(4);

    $grantTriggers = DB::table('pg_trigger')
        ->whereRaw("tgrelid = 'download_grants'::regclass")
        ->where('tgisinternal', false)
        ->pluck('tgdeferrable', 'tgname');

    expect($grantTriggers)->toHaveCount(4)
        ->and($grantTriggers['download_grants_prevent_delete_trigger'])->toBeFalse()
        ->and($grantTriggers['download_grants_enforce_immutability_trigger'])->toBeFalse()
        ->and($grantTriggers['download_grants_validate_delivery_trigger'])->toBeFalse()
        // G4 is deferred, and mounted on BOTH domains.
        ->and($grantTriggers['download_grants_validate_order_consistency_trigger'])->toBeTrue();

    $orderTrigger = DB::table('pg_trigger')
        ->whereRaw("tgrelid = 'orders'::regclass")
        ->where('tgname', 'orders_validate_download_consistency_trigger')
        ->first(['tgdeferrable', 'tginitdeferred']);

    expect($orderTrigger)->not->toBeNull()
        ->and($orderTrigger->tgdeferrable)->toBeTrue()
        ->and($orderTrigger->tginitdeferred)->toBeTrue();

    // P4-A2 never touches refunds; `licenses` stays excluded from P4 (D-029).
    expect(DB::table('pg_trigger')->whereRaw("tgrelid = 'refunds'::regclass")->where('tgisinternal', false)->where('tgname', 'like', '%download%')->count())->toBe(0)
        ->and(Schema::hasTable('licenses'))->toBeFalse();
});

it('issues a grant on a deliverable direct purchase and stores only the digest', function () {
    $purchase = createP4A2DirectPurchase();
    $rawToken = bin2hex(random_bytes(32));

    $grant = DownloadGrant::factory()
        ->forOrderItem($purchase['item'])
        ->forProductFile($purchase['file'])
        ->create(['token_hash' => hash('sha256', $rawToken)]);

    expect($grant->exists)->toBeTrue()
        ->and($grant->downloads_count)->toBe(0)
        ->and($grant->revoked_at)->toBeNull()
        ->and($grant->orderItem->is($purchase['item']))->toBeTrue()
        ->and($grant->productFile->is($purchase['file']))->toBeTrue()
        ->and($purchase['item']->downloadGrants()->count())->toBe(1)
        ->and($purchase['file']->downloadGrants()->count())->toBe(1);

    // The raw token appears nowhere in the row; the digest is hidden from arrays.
    $row = (array) DB::table('download_grants')->where('id', $grant->id)->first();

    foreach ($row as $value) {
        expect((string) $value)->not->toContain($rawToken);
    }

    expect($row['token_hash'])->toBe(hash('sha256', $rawToken))
        ->and($grant->fresh()->toArray())->not->toHaveKey('token_hash');
});

it('accepts a partially refunded order and refuses every non-deliverable order status', function () {
    $partially = createP4A2DirectPurchase(OrderStatus::PartiallyRefunded);

    expect(DownloadGrant::factory()->forOrderItem($partially['item'])->forProductFile($partially['file'])->create()->exists)->toBeTrue();

    foreach ([OrderStatus::Pending, OrderStatus::PaymentReview, OrderStatus::Cancelled, OrderStatus::Expired, OrderStatus::Refunded] as $status) {
        $purchase = createP4A2DirectPurchase($status);

        expectP4A2TriggerViolation(
            fn () => DB::table('download_grants')->insert([
                'public_id' => (string) Str::uuid(),
                'order_item_id' => $purchase['item']->id,
                'product_file_id' => $purchase['file']->id,
                'user_id' => null,
                'token_hash' => p4a2TokenHash(),
                'expires_at' => now()->addDay(),
                'max_downloads' => 5,
                'downloads_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'download_grants require a deliverable order',
        );
    }
});

it('refuses a product file that does not belong to the purchased product', function () {
    $purchase = createP4A2DirectPurchase();
    $otherPurchase = createP4A2DirectPurchase();

    expectP4A2TriggerViolation(
        fn () => DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($otherPurchase['file'])->create(),
        'download_grants product_file must belong to the purchased product',
    );

    // An inactive file blocks new issuance without rewriting existing grants.
    $inactive = ProductFile::factory()->create(['product_id' => $purchase['product']->getKey(), 'is_active' => false]);

    expectP4A2TriggerViolation(
        fn () => DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($inactive)->create(),
        'download_grants require an active product file',
    );

    // A purged product leaves order_items.product_id NULL: lineage unprovable.
    $purged = createP4A2DirectPurchase();
    $purgedFileId = $purged['file']->id;
    DB::table('download_grants')->where('product_file_id', $purgedFileId)->delete();
});

it('proves bundle lineage only against the purchase snapshot', function () {
    $purchase = createP4A2BundlePurchase();

    // File owned by the bundle product itself.
    expect(DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['bundleFile'])->create()->exists)->toBeTrue();

    // File owned by a snapshotted component.
    expect(DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['componentFile'])->create()->exists)->toBeTrue();

    // A product added to the live pivot AFTER the purchase is never deliverable:
    // no fallback to product_bundles exists.
    $late = Product::factory()->create();
    $lateFile = ProductFile::factory()->create(['product_id' => $late->getKey()]);
    $purchase['bundle']->childProducts()->attach($late->getKey(), ['position' => 9]);

    expectP4A2TriggerViolation(
        fn () => DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($lateFile)->create(),
        'download_grants product_file must belong to the purchased bundle',
    );
});

it('refuses every grant on a bundle order item whose snapshot is totally absent', function () {
    $purchase = createP4A2BundlePurchase(withSnapshot: false);

    expect($purchase['item']->bundleComponents()->count())->toBe(0);

    expectP4A2TriggerViolation(
        fn () => DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['bundleFile'])->create(),
        'download_grants require a bundle purchase snapshot',
    );

    expectP4A2TriggerViolation(
        fn () => DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['componentFile'])->create(),
        'download_grants require a bundle purchase snapshot',
    );

    // A PARTIAL snapshot is NOT detectable (D-029.4, finding 1): P4-A1 stores no
    // header, no expected count and no completeness proof, and comparing to the live
    // pivot is forbidden. A missing component simply yields no grant for that
    // component — under-delivery is possible, over-delivery is not.
    $partial = createP4A2BundlePurchase();
    $missing = Product::factory()->create();
    $missingFile = ProductFile::factory()->create(['product_id' => $missing->getKey()]);
    $partial['bundle']->childProducts()->attach($missing->getKey(), ['position' => 1]);

    // The snapshot only holds one of the two components; nothing flags it as partial.
    expect($partial['item']->bundleComponents()->count())->toBe(1)
        ->and(DownloadGrant::factory()->forOrderItem($partial['item'])->forProductFile($partial['componentFile'])->create()->exists)->toBeTrue();

    expectP4A2TriggerViolation(
        fn () => DownloadGrant::factory()->forOrderItem($partial['item'])->forProductFile($missingFile)->create(),
        'download_grants product_file must belong to the purchased bundle',
    );
});

it('validates lineage but never temporality: a file added after the purchase is not blocked by the database', function () {
    $purchase = createP4A2DirectPurchase();

    // A brand-new file on the SAME purchased product, created after the order.
    $laterFile = ProductFile::factory()->create([
        'product_id' => $purchase['product']->getKey(),
        'version' => '2.0',
        'storage_path' => 'products/'.fake()->uuid().'/v2/file.zip',
    ]);

    // G3 accepts it: the lineage is genuine. "No implicit upgrade" is an APPLICATION
    // guarantee (D-029.4, option A) — the listener only issues at OrderPaid, and
    // rotation/re-issue keep the same product_file_id. PostgreSQL does NOT enforce it.
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($laterFile)->create();

    expect($grant->exists)->toBeTrue();
});

it('enforces the token digest format and its uniqueness', function () {
    $purchase = createP4A2DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create();

    $second = createP4A2DirectPurchase();

    expectP4A2QueryException(
        fn () => DownloadGrant::factory()->forOrderItem($second['item'])->forProductFile($second['file'])->create(['token_hash' => $grant->token_hash]),
        '23505',
        'download_grants_token_hash_unique',
    );

    foreach (['not-a-digest', strtoupper(p4a2TokenHash()), substr(p4a2TokenHash(), 0, 63)] as $invalid) {
        expectP4A2QueryException(
            fn () => DownloadGrant::factory()->forOrderItem($second['item'])->forProductFile($second['file'])->create(['token_hash' => $invalid]),
            '23514',
            'download_grants_token_hash_format_check',
        );
    }

    expectP4A2QueryException(
        fn () => DownloadGrant::factory()->forOrderItem($second['item'])->forProductFile($second['file'])->create(['public_id' => $grant->public_id]),
        '23505',
        'download_grants_public_id_unique',
    );
});

it('enforces quota and expiry bounds without any commercial default', function () {
    $purchase = createP4A2DirectPurchase();

    $insert = fn (array $overrides): Closure => fn () => DB::table('download_grants')->insert(array_merge([
        'public_id' => (string) Str::uuid(),
        'order_item_id' => $purchase['item']->id,
        'product_file_id' => $purchase['file']->id,
        'user_id' => null,
        'token_hash' => p4a2TokenHash(),
        'expires_at' => now()->addDay(),
        'max_downloads' => 5,
        'downloads_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    expectP4A2QueryException($insert(['max_downloads' => 0]), '23514', 'download_grants_max_downloads_positive_check');
    // max_downloads = -1 breaks BOTH bounds at once (0 <= -1 is false); PostgreSQL
    // reports the quota bound, which proves the counter can never exceed the quota.
    expectP4A2QueryException($insert(['max_downloads' => -1]), '23514', 'download_grants_count_within_quota_check');
    expectP4A2QueryException($insert(['expires_at' => now()->subDay()]), '23514', 'download_grants_expires_after_created_check');
    expectP4A2QueryException($insert(['expires_at' => null]), '23502', 'expires_at');
    expectP4A2QueryException($insert(['max_downloads' => null]), '23502', 'max_downloads');

    // A born-consumed or born-revoked grant is refused by G3, which runs BEFORE the
    // row CHECKs: `download_grants_count_within_quota_check` therefore stays a
    // defence-in-depth bound (asserted structurally above), unreachable at INSERT.
    expectP4A2TriggerViolation($insert(['downloads_count' => 1]), 'download_grants are born unconsumed and active');
    expectP4A2TriggerViolation($insert(['downloads_count' => -1]), 'download_grants are born unconsumed and active');
    expectP4A2TriggerViolation($insert(['downloads_count' => 6, 'max_downloads' => 5]), 'download_grants are born unconsumed and active');
    expectP4A2TriggerViolation($insert(['revoked_at' => now(), 'revoked_reason_code' => 'support']), 'download_grants are born unconsumed and active');
});

it('freezes identity, token and bounds while allowing an identical assignment', function () {
    $purchase = createP4A2DirectPurchase();
    $other = createP4A2DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create();
    $original = DB::table('download_grants')->where('id', $grant->id)->first();

    $message = 'download_grants identity, token and bounds are immutable';

    $forbidden = [
        'id' => $grant->id + 500,
        'public_id' => (string) Str::uuid(),
        'order_item_id' => $other['item']->id,
        'product_file_id' => $other['file']->id,
        'token_hash' => p4a2TokenHash(),
        'expires_at' => now()->addYear(),
        'max_downloads' => 99,
        'created_at' => now()->subYear(),
    ];

    foreach ($forbidden as $column => $value) {
        expectP4A2TriggerViolation(
            fn () => DB::table('download_grants')->where('id', $grant->id)->update([$column => $value]),
            $message,
        );
    }

    // Rotation towards another product_file is not a rotation: it is refused here.
    expectP4A2TriggerViolation(
        fn () => $grant->fresh()->update(['product_file_id' => $other['file']->id]),
        $message,
    );

    expect(DB::table('download_grants')->where('id', $grant->id)->first())->toEqual($original);

    $affected = DB::table('download_grants')->where('id', $grant->id)->update([
        'id' => $original->id,
        'public_id' => $original->public_id,
        'order_item_id' => $original->order_item_id,
        'product_file_id' => $original->product_file_id,
        'token_hash' => $original->token_hash,
        'expires_at' => $original->expires_at,
        'max_downloads' => $original->max_downloads,
        'created_at' => $original->created_at,
    ]);

    expect($affected)->toBe(1)
        ->and(DB::table('download_grants')->where('id', $grant->id)->first())->toEqual($original);
});

it('bounds the consumption counter structurally and refuses every direct write since P4-B', function () {
    $purchase = createP4A2DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create(['max_downloads' => 2]);

    // Since P4-B, consumption is REAL and paired with a download log: the exact
    // +1 is only accepted from the nested G5 UPDATE. Every direct write path is
    // refused; the P4-B suite proves the nested path and quota exhaustion.
    expectP4A2TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update(['downloads_count' => 1]),
        'download_grants consumption must originate from the download log executor',
    );
    expect((int) DB::table('download_grants')->where('id', $grant->id)->value('downloads_count'))->toBe(0);

    $fresh = createP4A2DirectPurchase();
    $second = DownloadGrant::factory()->forOrderItem($fresh['item'])->forProductFile($fresh['file'])->create(['max_downloads' => 3]);

    // Never backwards, never by more than one (still reported precisely).
    expectP4A2TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $second->id)->update(['downloads_count' => 2]),
        'download_grants downloads_count may only increase by exactly one',
    );
    expectP4A2TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $second->id)->update(['downloads_count' => -1]),
        'download_grants downloads_count may only increase by exactly one',
    );

    // Expired and revoked grants cannot be consumed.
    $expiredPurchase = createP4A2DirectPurchase();
    $expired = DownloadGrant::factory()->forOrderItem($expiredPurchase['item'])->forProductFile($expiredPurchase['file'])->expired()->create();
    expectP4A2TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $expired->id)->update(['downloads_count' => 1]),
        'download_grants cannot be consumed once expired',
    );

    // A grant is born active (G3): revocation is only reachable through an UPDATE.
    $revokedPurchase = createP4A2DirectPurchase();
    $revoked = DownloadGrant::factory()->forOrderItem($revokedPurchase['item'])->forProductFile($revokedPurchase['file'])->create();
    DB::table('download_grants')->where('id', $revoked->id)->update(['revoked_at' => now(), 'revoked_reason_code' => 'support']);

    expectP4A2TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $revoked->id)->update(['downloads_count' => 1]),
        'download_grants cannot be consumed once revoked',
    );
});

it('makes revocation irreversible, paired with its reason, and never combined with consumption', function () {
    $purchase = createP4A2DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create();

    // Revocation must carry a reason (CHECK pairing).
    expectP4A2QueryException(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update(['revoked_at' => now()]),
        '23514',
        'download_grants_revocation_pair_check',
    );

    expect(DB::table('download_grants')->where('id', $grant->id)->update([
        'revoked_at' => now(),
        'revoked_reason_code' => 'fraud',
    ]))->toBe(1);

    // Irreversible, and the reason freezes with it.
    expectP4A2TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update(['revoked_at' => null, 'revoked_reason_code' => null]),
        'download_grants revocation is irreversible',
    );
    expectP4A2TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update(['revoked_at' => now()->addHour()]),
        'download_grants revocation is irreversible',
    );
    expectP4A2TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $grant->id)->update(['revoked_reason_code' => 'support']),
        'download_grants revocation reason is frozen once revoked',
    );

    // Consumption and revocation are never combined in one UPDATE.
    $fresh = createP4A2DirectPurchase();
    $second = DownloadGrant::factory()->forOrderItem($fresh['item'])->forProductFile($fresh['file'])->create();

    expectP4A2TriggerViolation(
        fn () => DB::table('download_grants')->where('id', $second->id)->update([
            'revoked_at' => now(),
            'revoked_reason_code' => 'support',
            'downloads_count' => 1,
        ]),
        'download_grants consumption and revocation cannot be combined',
    );
});

it('allows only one active grant per pair and rotates through revoke then re-issue', function () {
    $purchase = createP4A2DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create();

    // A second active grant for the same pair is refused.
    expectP4A2QueryException(
        fn () => DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create(),
        '23505',
        'download_grants_active_pair_unique',
    );

    // Rotation: revoke, then re-issue the SAME pair with a fresh digest.
    DB::table('download_grants')->where('id', $grant->id)->update(['revoked_at' => now(), 'revoked_reason_code' => 'token_compromised']);
    $rotated = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create();

    expect($rotated->id)->not->toBe($grant->id)
        ->and($rotated->product_file_id)->toBe($grant->product_file_id)
        ->and($rotated->order_item_id)->toBe($grant->order_item_id)
        ->and($rotated->token_hash)->not->toBe($grant->token_hash)
        ->and(DB::table('download_grants')->where('id', $grant->id)->value('token_hash'))->toBe($grant->token_hash);

    // An EXPIRED but non-revoked grant still occupies the active slot (finding 2):
    // it must be revoked with `expired_reissue` before a new one is issued.
    $expiredPurchase = createP4A2DirectPurchase();
    $expiredGrant = DownloadGrant::factory()->forOrderItem($expiredPurchase['item'])->forProductFile($expiredPurchase['file'])->expired()->create();

    expectP4A2QueryException(
        fn () => DownloadGrant::factory()->forOrderItem($expiredPurchase['item'])->forProductFile($expiredPurchase['file'])->create(),
        '23505',
        'download_grants_active_pair_unique',
    );

    DB::table('download_grants')->where('id', $expiredGrant->id)->update(['revoked_at' => now(), 'revoked_reason_code' => 'expired_reissue']);

    expect(DownloadGrant::factory()->forOrderItem($expiredPurchase['item'])->forProductFile($expiredPurchase['file'])->create()->exists)->toBeTrue();
});

it('ties the buyer reference to the order and nulls it only through the foreign key action', function () {
    $user = User::factory()->create();
    $purchase = createP4A2DirectPurchase(OrderStatus::Paid, $user);
    $other = User::factory()->create();

    // G3 refuses a grant whose user contradicts the order buyer.
    expectP4A2TriggerViolation(
        fn () => DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create(['user_id' => $other->id]),
        'download_grants user must match the order buyer',
    );

    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create();
    expect($grant->user_id)->toBe($user->id);

    $message = 'download_grants user reference may only be nulled by the foreign key action';

    expectP4A2TriggerViolation(fn () => DB::table('download_grants')->where('id', $grant->id)->update(['user_id' => null]), $message);
    expectP4A2TriggerViolation(fn () => DB::table('download_grants')->where('id', $grant->id)->update(['user_id' => $other->id]), $message);

    // The real FK action preserves the grant: only user_id becomes NULL.
    DB::table('users')->where('id', $user->id)->delete();

    $after = DB::table('download_grants')->where('id', $grant->id)->first();
    expect($after->user_id)->toBeNull()
        ->and($after->token_hash)->toBe($grant->token_hash)
        ->and($after->order_item_id)->toBe($purchase['item']->id);
});

it('refuses physical deletion of a grant and of the product file it references', function () {
    $purchase = createP4A2DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create();

    $message = 'download_grants are revoked, never deleted';

    expectP4A2TriggerViolation(fn () => DB::table('download_grants')->where('id', $grant->id)->delete(), $message);
    expectP4A2TriggerViolation(fn () => DB::table('download_grants')->whereNotNull('id')->delete(), $message);
    expectP4A2TriggerViolation(fn () => $grant->fresh()->delete(), $message);

    // RESTRICT keeps the delivered object alive, and the cascade from products hits it.
    expectP4A2QueryException(
        fn () => DB::table('product_files')->where('id', $purchase['file']->id)->delete(),
        '23503',
        'download_grants_product_file_id_foreign',
    );
    expectP4A2QueryException(
        fn () => DB::table('products')->where('id', $purchase['product']->id)->delete(),
        '23503',
        'download_grants_product_file_id_foreign',
    );

    expect(DB::table('download_grants')->count())->toBe(1);
});

it('requires every active grant to belong to a deliverable order at commit', function () {
    $purchase = createP4A2DirectPurchase();
    $grant = DownloadGrant::factory()->forOrderItem($purchase['item'])->forProductFile($purchase['file'])->create();
    $payment = Payment::query()->where('order_id', $purchase['order']->id)->firstOrFail();

    // A total refund that forgets to revoke the grants is refused at COMMIT.
    expectP4A2TriggerViolation(function () use ($purchase, $payment): void {
        Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => $payment->amount_minor]);
        DB::table('orders')->where('id', $purchase['order']->id)->update(['status' => OrderStatus::Refunded->value]);
    }, 'active download_grants require a deliverable order');

    expect(DB::table('download_grants')->where('id', $grant->id)->value('revoked_at'))->toBeNull()
        ->and(DB::table('orders')->where('id', $purchase['order']->id)->value('status'))->toBe(OrderStatus::Paid->value);

    // The same transaction, done properly: revoke, refund and move the order together.
    DB::transaction(function () use ($purchase, $payment, $grant): void {
        DB::table('download_grants')->where('id', $grant->id)->update(['revoked_at' => now(), 'revoked_reason_code' => 'refund']);
        Refund::factory()->forPayment($payment)->succeeded()->create(['amount_minor' => $payment->amount_minor]);
        DB::table('orders')->where('id', $purchase['order']->id)->update(['status' => OrderStatus::Refunded->value]);
        forceP4A2Constraints();
    });

    expect(DB::table('orders')->where('id', $purchase['order']->id)->value('status'))->toBe(OrderStatus::Refunded->value)
        ->and(DB::table('download_grants')->where('id', $grant->id)->value('revoked_at'))->not->toBeNull();

    // A partial refund never revokes anything automatically.
    $partial = createP4A2DirectPurchase(OrderStatus::PartiallyRefunded);
    $partialGrant = DownloadGrant::factory()->forOrderItem($partial['item'])->forProductFile($partial['file'])->create();

    expect($partialGrant->fresh()->revoked_at)->toBeNull();
});

it('serialises issuance against a concurrent refund through the order lock', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4a2_concurrency_'.strtolower(Str::random(10)));
    $connection = config('database.connections.pgsql_migration');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName());

    $issuer = null;
    $refunder = null;

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000010_create_download_grants_table.php');

        $seed = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $seed->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES ('conc-prod', 'Concurrent product', 'ebook', 'published', now(), now())");
        $seed->exec("INSERT INTO product_files (product_id, storage_disk, storage_path, original_name, size_bytes, checksum_sha256, version) SELECT id, 'private', 'products/conc/file.zip', 'file.zip', 10, '".hash('sha256', 'conc')."', '1.0' FROM products WHERE slug = 'conc-prod'");
        $seed->beginTransaction();
        $seed->exec("INSERT INTO orders (public_id, order_number, checkout_idempotency_hash, customer_email, subtotal_minor, discount_minor, tax_minor, total_minor, currency, status, placed_at, expires_at, paid_at, created_at, updated_at) VALUES (gen_random_uuid(), 'DGT-2026-CNC0000002', '".hash('sha256', 'conc-a2')."', 'conc@example.test', 10000, 0, 0, 10000, 'XOF', 'paid', now(), now() + interval '30 minutes', now(), now(), now())");
        $seed->exec("INSERT INTO order_items (order_id, product_id, product_name_snapshot, product_slug_snapshot, product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor, line_discount_minor, line_total_minor, currency, created_at, updated_at) SELECT o.id, p.id, 'Concurrent product', 'conc-prod', 'ebook', 10000, 1, 10000, 0, 10000, 'XOF', now(), now() FROM orders o, products p WHERE o.order_number='DGT-2026-CNC0000002' AND p.slug='conc-prod'");
        $seed->exec("INSERT INTO payments (public_id, order_id, provider, idempotency_key_hash, attempt_number, amount_minor, currency, status, succeeded_at, created_at, updated_at) SELECT gen_random_uuid(), o.id, 'powerpay', '".hash('sha256', 'pay-a2')."', 1, 10000, 'XOF', 'succeeded', now(), now(), now() FROM orders o WHERE o.order_number='DGT-2026-CNC0000002'");
        $seed->commit();
        $seed = null;

        $issuer = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $refunder = new PDO($dsn, $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // The issuer inserts a grant: G3 takes the order row lock and holds it.
        $issuer->beginTransaction();
        $issuer->exec("INSERT INTO download_grants (public_id, order_item_id, product_file_id, token_hash, expires_at, max_downloads, created_at, updated_at) SELECT gen_random_uuid(), oi.id, pf.id, '".hash('sha256', 'tok-a2')."', now() + interval '72 hours', 5, now(), now() FROM order_items oi, product_files pf WHERE oi.product_slug_snapshot='conc-prod' AND pf.storage_path='products/conc/file.zip'");

        // A refund flow correctly locking the same order row must wait.
        $refunder->exec("SET lock_timeout = '1000ms'");
        $refunder->beginTransaction();

        $blocked = null;

        try {
            $refunder->exec("SELECT id FROM orders WHERE order_number = 'DGT-2026-CNC0000002' FOR UPDATE");
        } catch (PDOException $pdoException) {
            $blocked = $pdoException;
        }

        expect($blocked)->not->toBeNull()
            ->and($blocked->getCode())->toBe('55P03');

        $refunder->rollBack();
        $issuer->commit();

        expect((int) $issuer->query('SELECT COUNT(*) FROM download_grants')->fetchColumn())->toBe(1);

        // Two concurrent issuances of the same pair: only one survives.
        $duplicate = null;

        try {
            $refunder->exec("INSERT INTO download_grants (public_id, order_item_id, product_file_id, token_hash, expires_at, max_downloads, created_at, updated_at) SELECT gen_random_uuid(), oi.id, pf.id, '".hash('sha256', 'tok-a2-dup')."', now() + interval '72 hours', 5, now(), now() FROM order_items oi, product_files pf WHERE oi.product_slug_snapshot='conc-prod' AND pf.storage_path='products/conc/file.zip'");
        } catch (PDOException $pdoException) {
            $duplicate = $pdoException;
        }

        expect($duplicate)->not->toBeNull()
            ->and($duplicate->getCode())->toBe('23505')
            ->and($duplicate->getMessage())->toContain('download_grants_active_pair_unique');
    } finally {
        $issuer = null;
        $refunder = null;
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

it('rolls back only the P4-A2 gate while preserving P4-A1, P4-A0 and every earlier phase', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4a2_rollback_'.strtolower(Str::random(10)));

    $boundary = '2026_07_14_000010_create_download_grants_table.php';
    $functions = ['prevent_download_grants_delete', 'enforce_download_grants_immutability', 'validate_download_grant_delivery', 'validate_download_grant_order_consistency'];
    $triggers = ['download_grants_prevent_delete_trigger', 'download_grants_enforce_immutability_trigger', 'download_grants_validate_delivery_trigger', 'download_grants_validate_order_consistency_trigger', 'orders_validate_download_consistency_trigger'];
    $p4a1Functions = ['prevent_order_item_bundle_components_delete', 'enforce_order_item_bundle_component_immutability', 'validate_order_item_bundle_component'];

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($boundary);

        expect(end($applied))->toBe('2026_07_14_000010_create_download_grants_table')
            ->and($applied)->toContain('2026_07_14_000009_create_order_item_bundle_components_table')
            ->and($harness->hasTable('download_grants'))->toBeTrue()
            ->and($harness->countFunctions($functions))->toBe(4)
            ->and($harness->countTriggers($triggers))->toBe(5)
            ->and($harness->countFunctions(['enforce_product_file_content_immutability']))->toBe(1)
            ->and($harness->countFunctions($p4a1Functions))->toBe(3);

        $downed = $harness->rollbackExactMigrations([$boundary]);

        expect($downed)->toBe(['2026_07_14_000010_create_download_grants_table'])
            ->and($harness->hasTable('download_grants'))->toBeFalse()
            ->and($harness->countFunctions($functions))->toBe(0)
            ->and($harness->countTriggers($triggers))->toBe(0);

        // P4-A1 and P4-A0 survive untouched, as does every earlier phase.
        expect($harness->hasTable('order_item_bundle_components'))->toBeTrue()
            ->and($harness->countFunctions($p4a1Functions))->toBe(3)
            ->and($harness->countFunctions(['enforce_product_file_content_immutability']))->toBe(1)
            ->and($harness->countTriggers(['product_files_enforce_content_immutability_trigger']))->toBe(1)
            ->and($harness->hasTable('products'))->toBeTrue()
            ->and($harness->hasTable('product_files'))->toBeTrue()
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->hasTable('order_items'))->toBeTrue()
            ->and($harness->hasTable('payments'))->toBeTrue()
            ->and($harness->hasTable('refunds'))->toBeTrue()
            ->and($harness->hasConstraint('orders_coupon_snapshot_consistency_check'))->toBeTrue();

        $remaining = $harness->ranMigrations();
        expect(end($remaining))->toBe('2026_07_14_000009_create_order_item_bundle_components_table')
            ->and($remaining)->not->toContain('2026_07_14_000010_create_download_grants_table');

        expect($harness->hasTable('download_logs'))->toBeFalse();
    } finally {
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});
