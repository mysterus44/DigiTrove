<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * A NON-transactional database strategy for P3-D3.
 *
 * The payment initiation service refuses to run inside an ambient transaction
 * (`DB::transactionLevel()` must be 0), so its tests cannot use RefreshDatabase,
 * which wraps every test in one open transaction. Instead:
 *
 *  - the schema is built once through the MIGRATOR/owner connection;
 *  - each test starts from a clean slate via TRUNCATE, run by the MIGRATOR only
 *    (the runtime role has no TRUNCATE privilege, and TRUNCATE also bypasses the
 *    BEFORE DELETE prevent-delete triggers on orders/order_items/payments);
 *  - business queries still run on the default `pgsql` connection, i.e. under
 *    `digitrove_runtime`, exactly like production.
 *
 * Nothing here relaxes an ACL or touches PhaseMigrationHarness /
 * RefreshesDatabaseAsMigrator.
 */
trait InteractsWithPaymentsDatabase
{
    protected static bool $paymentsSchemaMigrated = false;

    // Laravel's setUpTraits() auto-calls setUp{ClassBasename} for each used trait.
    protected function setUpInteractsWithPaymentsDatabase(): void
    {
        if (! static::$paymentsSchemaMigrated) {
            $this->artisan('migrate:fresh', [
                '--database' => 'pgsql_migration',
                '--force' => true,
            ])->run();

            static::$paymentsSchemaMigrated = true;
        }

        $this->truncateApplicationTables();

        // Without RefreshDatabase there is no per-test transaction to close, so
        // each fresh test application would otherwise leave its PostgreSQL
        // connections open until GC and exhaust max_connections. Disconnect
        // them explicitly when the application is torn down.
        $this->beforeApplicationDestroyed(function (): void {
            DB::disconnect('pgsql');
            DB::disconnect('pgsql_migration');
        });
    }

    protected function truncateApplicationTables(): void
    {
        // The owner truncates; the runtime role never does. RESTART IDENTITY
        // keeps ids predictable, CASCADE follows the foreign keys.
        $migrator = DB::connection('pgsql_migration');

        /** @var list<string> $tables */
        $tables = array_map(
            static fn (object $row): string => $row->tablename,
            $migrator->select(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"
            ),
        );

        if ($tables === []) {
            return;
        }

        $quoted = implode(', ', array_map(static fn (string $t): string => '"'.$t.'"', $tables));

        $migrator->statement("TRUNCATE {$quoted} RESTART IDENTITY CASCADE");
    }
}
