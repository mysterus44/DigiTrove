<?php

namespace Tests\Concerns;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
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
            DB::disconnect('pgsql');
            DB::disconnect('pgsql_migration');
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
