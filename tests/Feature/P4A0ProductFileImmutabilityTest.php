<?php

use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

uses(RefreshDatabase::class);

function expectP4A0ImmutabilityViolation(Closure $callback, string $column): void
{
    $exception = null;

    try {
        DB::transaction($callback);
    } catch (QueryException $queryException) {
        $exception = $queryException;
    }

    expect($exception)->not->toBeNull()
        ->and((string) $exception->getCode())->toBe('23514')
        ->and($exception->getMessage())->toContain('product_files content is immutable; a new content version requires a new row')
        ->and($exception->getMessage())->toContain('Immutable column: '.$column);
}

function createP4A0File(array $attributes = []): ProductFile
{
    return ProductFile::factory()->create(array_merge([
        'storage_path' => 'products/'.fake()->uuid().'/version-1/file.zip',
        'original_name' => 'file-v1.zip',
        'mime_type' => 'application/zip',
        'size_bytes' => 2048,
        'checksum_sha256' => hash('sha256', 'p4a0-content-'.Str::random(12)),
        'version' => '1.0',
        'position' => 0,
        'is_active' => true,
    ], $attributes));
}

it('applies migration 000008 with the G0 function and BEFORE UPDATE trigger on product_files only', function () {
    expect(DB::table('migrations')->where('migration', '2026_07_14_000008_harden_product_files_content_immutability')->exists())->toBeTrue();

    expect((int) DB::table('pg_proc')->where('proname', 'enforce_product_file_content_immutability')->count())->toBe(1);

    $trigger = DB::table('information_schema.triggers')
        ->where('trigger_name', 'product_files_enforce_content_immutability_trigger')
        ->get(['event_manipulation', 'action_timing', 'event_object_table']);

    expect($trigger)->toHaveCount(1)
        ->and($trigger->first()->event_manipulation)->toBe('UPDATE')
        ->and($trigger->first()->action_timing)->toBe('BEFORE')
        ->and($trigger->first()->event_object_table)->toBe('product_files');

    $definition = (string) DB::selectOne(
        "SELECT pg_get_functiondef(oid) AS def FROM pg_proc WHERE proname = 'enforce_product_file_content_immutability'",
    )->def;

    expect($definition)->toContain('IS DISTINCT FROM');

    foreach (['id', 'product_id', 'storage_disk', 'storage_path', 'checksum_sha256', 'size_bytes', 'mime_type', 'version', 'created_at'] as $column) {
        expect($definition)->toContain('OLD.'.$column);
    }

    foreach (['original_name', 'position', 'is_active'] as $mutable) {
        expect($definition)->not->toContain('OLD.'.$mutable);
    }
});

it('rejects any in-place change of each content identity column and preserves the original value', function () {
    $otherProduct = Product::factory()->create();
    $file = createP4A0File();
    $original = DB::table('product_files')->where('id', $file->id)->first();

    $forbiddenUpdates = [
        'product_id' => $otherProduct->id,
        'storage_disk' => 'restricted',
        'storage_path' => 'products/'.fake()->uuid().'/version-2/file.zip',
        'checksum_sha256' => hash('sha256', 'tampered-content'),
        'size_bytes' => 4096,
        'mime_type' => 'application/pdf',
        'version' => '2.0',
        'created_at' => now()->addHour(),
    ];

    foreach ($forbiddenUpdates as $column => $value) {
        expectP4A0ImmutabilityViolation(
            fn () => DB::table('product_files')->where('id', $file->id)->update([$column => $value]),
            $column,
        );
    }

    // The primary key is row identity and is frozen too (project precedent P3B/P3C).
    expectP4A0ImmutabilityViolation(
        fn () => DB::table('product_files')->where('id', $file->id)->update(['id' => $file->id + 1000]),
        'id',
    );

    $after = DB::table('product_files')->where('id', $file->id)->first();
    expect($after)->toEqual($original);
});

it('accepts an update that re-assigns every protected column to its strictly identical value', function () {
    $file = createP4A0File();
    $original = DB::table('product_files')->where('id', $file->id)->first();

    $affected = DB::table('product_files')->where('id', $file->id)->update([
        'id' => $original->id,
        'product_id' => $original->product_id,
        'storage_disk' => $original->storage_disk,
        'storage_path' => $original->storage_path,
        'checksum_sha256' => $original->checksum_sha256,
        'size_bytes' => $original->size_bytes,
        'mime_type' => $original->mime_type,
        'version' => $original->version,
        'created_at' => $original->created_at,
    ]);

    expect($affected)->toBe(1)
        ->and(DB::table('product_files')->where('id', $file->id)->first())->toEqual($original);
});

it('accepts updates limited to the mutable display columns, separately and combined', function () {
    $file = createP4A0File();

    expect(DB::table('product_files')->where('id', $file->id)->update(['original_name' => 'renamed-display.zip']))->toBe(1);
    expect(DB::table('product_files')->where('id', $file->id)->update(['position' => 7]))->toBe(1);
    expect(DB::table('product_files')->where('id', $file->id)->update(['is_active' => false]))->toBe(1);

    expect(DB::table('product_files')->where('id', $file->id)->update([
        'original_name' => 'renamed-again.zip',
        'position' => 9,
        'is_active' => true,
    ]))->toBe(1);

    $after = DB::table('product_files')->where('id', $file->id)->first();
    expect($after->original_name)->toBe('renamed-again.zip')
        ->and($after->position)->toBe(9)
        ->and($after->is_active)->toBeTrue();
});

it('rejects atomically an update mixing a mutable column with a protected column', function () {
    $file = createP4A0File(['original_name' => 'stable-name.zip', 'position' => 1, 'version' => '1.0']);

    expectP4A0ImmutabilityViolation(
        fn () => DB::table('product_files')->where('id', $file->id)->update([
            'original_name' => 'sneaky-rename.zip',
            'storage_path' => 'products/'.fake()->uuid().'/swapped/file.zip',
        ]),
        'storage_path',
    );

    expectP4A0ImmutabilityViolation(
        fn () => DB::table('product_files')->where('id', $file->id)->update([
            'position' => 42,
            'version' => '9.9',
        ]),
        'version',
    );

    $after = DB::table('product_files')->where('id', $file->id)->first();
    expect($after->original_name)->toBe('stable-name.zip')
        ->and($after->position)->toBe(1)
        ->and($after->version)->toBe('1.0')
        ->and($after->storage_path)->toBe($file->storage_path);
});

it('rejects protected changes through raw SQL and through Eloquent alike', function () {
    $file = createP4A0File();

    expectP4A0ImmutabilityViolation(
        fn () => DB::statement(
            'UPDATE product_files SET checksum_sha256 = ? WHERE id = ?',
            [hash('sha256', 'raw-sql-tamper'), $file->id],
        ),
        'checksum_sha256',
    );

    expectP4A0ImmutabilityViolation(
        fn () => $file->fresh()->update(['size_bytes' => 999999]),
        'size_bytes',
    );

    expect(DB::table('product_files')->where('id', $file->id)->value('checksum_sha256'))->toBe($file->checksum_sha256)
        ->and((int) DB::table('product_files')->where('id', $file->id)->value('size_bytes'))->toBe($file->size_bytes);
});

it('supports a new content version as a new row while the purchased row stays intact and deactivatable', function () {
    $product = Product::factory()->create();
    $versionA = createP4A0File([
        'product_id' => $product->id,
        'version' => '1.0',
        'original_name' => 'installer-v1.zip',
    ]);
    $snapshotA = DB::table('product_files')->where('id', $versionA->id)->first();

    $versionB = createP4A0File([
        'product_id' => $product->id,
        'storage_path' => 'products/'.fake()->uuid().'/version-2/installer.zip',
        'checksum_sha256' => hash('sha256', 'brand-new-content'),
        'size_bytes' => 8192,
        'version' => '2.0',
        'original_name' => 'installer-v2.zip',
    ]);

    // Version A is untouched by B's creation, then deactivated without any rewrite.
    expect(DB::table('product_files')->where('id', $versionA->id)->first())->toEqual($snapshotA);
    expect(DB::table('product_files')->where('id', $versionA->id)->update(['is_active' => false]))->toBe(1);

    $afterA = DB::table('product_files')->where('id', $versionA->id)->first();
    expect($afterA->is_active)->toBeFalse()
        ->and($afterA->storage_path)->toBe($snapshotA->storage_path)
        ->and($afterA->checksum_sha256)->toBe($snapshotA->checksum_sha256)
        ->and($afterA->version)->toBe('1.0');

    $afterB = DB::table('product_files')->where('id', $versionB->id)->first();
    expect($afterB->is_active)->toBeTrue()
        ->and($afterB->version)->toBe('2.0')
        ->and($afterB->checksum_sha256)->not->toBe($snapshotA->checksum_sha256)
        ->and((int) DB::table('product_files')->where('product_id', $product->id)->count())->toBe(2);
});

it('adds no side effects: rows, products, relations and future P4 tables are untouched', function () {
    $product = Product::factory()->create();
    $file = createP4A0File(['product_id' => $product->id]);

    expectP4A0ImmutabilityViolation(
        fn () => DB::table('product_files')->where('id', $file->id)->update(['version' => '3.0']),
        'version',
    );

    expect((int) DB::table('product_files')->count())->toBe(1)
        ->and(Product::query()->whereKey($product->id)->exists())->toBeTrue()
        ->and($file->fresh()->product->is($product))->toBeTrue();

    foreach (['licenses'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Unexpected future P4 table exists: {$table}");
    }
});

it('rolls back only the P4-A0 hardening while preserving product_files data and every earlier phase', function () {
    $harness = new PhaseMigrationHarness('digitrove_p4a0_rollback_'.strtolower(Str::random(10)));

    $boundary = '2026_07_14_000008_harden_product_files_content_immutability.php';
    $refundFunctions = ['prevent_refunds_delete', 'enforce_refunds_immutability', 'validate_refund_payment_consistency', 'enforce_refund_cumulative_cap', 'validate_refund_order_consistency'];

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough($boundary);
        expect(end($applied))->toBe('2026_07_14_000008_harden_product_files_content_immutability')
            ->and($applied)->toContain('2026_07_14_000007_create_refunds_table')
            ->and($harness->countFunctions(['enforce_product_file_content_immutability']))->toBe(1)
            ->and($harness->countTriggers(['product_files_enforce_content_immutability_trigger']))->toBe(1);

        // Seed one real product + file through PDO to prove the rollback keeps data.
        $connection = config('database.connections.pgsql');
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'] ?? 5432, $harness->databaseName()),
            $connection['username'],
            $connection['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec("INSERT INTO products (slug, name, type, status, created_at, updated_at) VALUES ('p4a0-proof', 'P4A0 proof', 'ebook', 'draft', now(), now())");
        $pdo->exec("INSERT INTO product_files (product_id, storage_disk, storage_path, original_name, size_bytes, checksum_sha256, version) SELECT id, 'private', 'products/p4a0/proof.zip', 'proof.zip', 10, '".hash('sha256', 'p4a0-proof')."', '1.0' FROM products WHERE slug = 'p4a0-proof'");

        // G0 active on the temporary database before the rollback.
        $blocked = null;

        try {
            $pdo->exec("UPDATE product_files SET version = '2.0' WHERE storage_path = 'products/p4a0/proof.zip'");
        } catch (PDOException $pdoException) {
            $blocked = $pdoException;
        }
        expect($blocked)->not->toBeNull()
            ->and((string) $blocked->getCode())->toBe('23514');

        $downed = $harness->rollbackExactMigrations([$boundary]);
        expect($downed)->toBe(['2026_07_14_000008_harden_product_files_content_immutability'])
            ->and($harness->countFunctions(['enforce_product_file_content_immutability']))->toBe(0)
            ->and($harness->countTriggers(['product_files_enforce_content_immutability_trigger']))->toBe(0);

        // The table, its data, and every earlier phase are preserved.
        expect($harness->hasTable('product_files'))->toBeTrue()
            ->and((int) $pdo->query("SELECT COUNT(*) FROM product_files WHERE storage_path = 'products/p4a0/proof.zip'")->fetchColumn())->toBe(1)
            ->and($harness->hasTable('refunds'))->toBeTrue()
            ->and($harness->hasTable('payment_webhook_events'))->toBeTrue()
            ->and($harness->hasTable('payments'))->toBeTrue()
            ->and($harness->hasTable('orders'))->toBeTrue()
            ->and($harness->countFunctions($refundFunctions))->toBe(5)
            ->and($harness->hasConstraint('orders_coupon_snapshot_consistency_check'))->toBeTrue();

        // Honest post-rollback behavior: without G0 the columns are mutable again.
        expect($pdo->exec("UPDATE product_files SET version = '2.0' WHERE storage_path = 'products/p4a0/proof.zip'"))->toBe(1);

        // Migrations ledger coherent, no future gate ever applied.
        $remaining = $harness->ranMigrations();
        expect(end($remaining))->toBe('2026_07_14_000007_create_refunds_table')
            ->and($remaining)->not->toContain('2026_07_14_000008_harden_product_files_content_immutability');

        foreach (['order_item_bundle_components', 'download_grants', 'download_logs'] as $table) {
            expect($harness->hasTable($table))->toBeFalse();
        }

        $pdo = null;
    } finally {
        $harness->drop();
    }

    expect(DB::table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});
