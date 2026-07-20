<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, but the schema is (re)built through the MIGRATION/owner
 * connection while the test itself keeps running its queries on the default
 * runtime connection (P4-B0, D-029.6).
 *
 * The default `pgsql` connection is the restricted `digitrove_runtime` role,
 * which deliberately cannot run DDL. Migrations therefore target
 * `pgsql_migration` (the `digitrove` owner), so migrate:fresh succeeds, while the
 * per-test transaction and every business query run under the runtime identity —
 * exactly the production topology, so the suite exercises the real privilege
 * boundary instead of hiding behind the owner.
 */
trait RefreshesDatabaseAsMigrator
{
    use RefreshDatabase {
        migrateFreshUsing as protected baseMigrateFreshUsing;
    }

    protected function migrateFreshUsing()
    {
        return array_merge(
            $this->baseMigrateFreshUsing(),
            ['--database' => 'pgsql_migration'],
        );
    }
}
