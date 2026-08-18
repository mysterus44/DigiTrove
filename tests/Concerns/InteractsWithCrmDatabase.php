<?php

namespace Tests\Concerns;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

trait InteractsWithCrmDatabase
{
    protected static bool $crmSchemaMigrated = false;

    protected function setUpInteractsWithCrmDatabase(): void
    {
        if (! static::$crmSchemaMigrated) {
            $this->artisan('migrate:fresh', [
                '--database' => 'pgsql_migration',
                '--force' => true,
            ])->run();

            static::$crmSchemaMigrated = true;
        }

        $this->truncateCrmApplicationTables();

        $this->beforeApplicationDestroyed(function (): void {
            // Leave the database as clean as we found it. This trait truncates on the way
            // IN, which is enough between its own tests — but it shares the process with
            // `RefreshDatabase` suites, and `migrate:fresh` runs at most ONCE per process.
            // A `RefreshDatabase` test that starts after this one therefore opens its
            // transaction on whatever rows we left, and an assertion like `sole()` fails
            // for a reason that has nothing to do with the code under test.
            DB::disconnect('pgsql');
            DB::disconnect('pgsql_migration');

            // This trait does NOT wrap its tests in a transaction, so the rows it wrote are
            // still there when the next test starts. `RefreshDatabase` runs `migrate:fresh`
            // at most ONCE per process, so a transactional test scheduled after this one
            // would open its transaction on our leftovers and fail on an assertion like
            // `sole()` for a reason unrelated to its subject.
            //
            // Forcing the flag back to false makes that next test rebuild the schema, which
            // is the mechanism Laravel already has for exactly this.
            //
            // ⚠️ Do NOT "fix" this by truncating here instead. It was tried and MEASURED: a
            // 54-table TRUNCATE at teardown leaves a lock-holding backend behind that the
            // next test's DDL deadlocks against (`40P01`, during `migrate:fresh` itself).
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function truncateCrmApplicationTables(): void
    {
        $migrator = DB::connection('pgsql_migration');
        $tables = array_map(
            static fn (object $row): string => $row->tablename,
            $migrator->select(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"
            ),
        );

        if ($tables === []) {
            return;
        }

        $quoted = implode(', ', array_map(static fn (string $table): string => '"'.$table.'"', $tables));
        $migrator->statement("TRUNCATE {$quoted} RESTART IDENTITY CASCADE");
    }

    protected function crmPendingOrder(string $email, ?User $user = null): Order
    {
        return DB::transaction(function () use ($email, $user): Order {
            $productId = Product::factory()->create()->id;
            $order = Order::factory()->create([
                'status' => OrderStatus::Pending,
                'customer_email' => $email,
                'user_id' => $user?->id,
                'subtotal_minor' => 5_000,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 5_000,
                'currency' => 'XOF',
            ]);

            DB::table('order_items')->insert([
                'order_id' => $order->id,
                'product_id' => null,
                'purchased_product_id' => $productId,
                'product_name_snapshot' => 'CRM fixture product',
                'product_slug_snapshot' => 'crm-fixture-product',
                'product_type_snapshot' => 'ebook',
                'unit_price_minor' => 5_000,
                'quantity' => 1,
                'line_subtotal_minor' => 5_000,
                'line_discount_minor' => 0,
                'line_total_minor' => 5_000,
                'currency' => 'XOF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $order;
        });
    }
}
