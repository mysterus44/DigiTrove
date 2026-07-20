<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase pinned to the MIGRATION/owner identity for the whole test
 * (P4-B0, D-029.6).
 *
 * Reserved for suites that probe DB-level triggers on protected objects — the
 * download_grants immutability/quota/revocation triggers (G1–G4). Those probes
 * mutate columns the restricted runtime deliberately cannot touch
 * (downloads_count, user_id, physical deletes), so under the runtime identity the
 * ACL would refuse them with 42501 *before* the trigger could answer 23514, and
 * the trigger logic would silently lose its coverage.
 *
 * Running them as the owner keeps the trigger layer honestly exercised. The
 * complementary proof — that the runtime is refused at the ACL layer for those
 * very same operations — lives in P4B0PostgreSQLRuntimePrivilegeBoundaryTest,
 * which runs under the real `digitrove_runtime` role. Neither layer is validated
 * by the superuser alone.
 */
trait RefreshesDatabaseAsOwner
{
    use RefreshDatabase {
        migrateFreshUsing as protected baseMigrateFreshUsing;
    }

    /** @return array<int, string|null> */
    protected function connectionsToTransact()
    {
        return ['pgsql_migration'];
    }

    protected function migrateFreshUsing()
    {
        return array_merge(
            $this->baseMigrateFreshUsing(),
            ['--database' => 'pgsql_migration'],
        );
    }

    /** @return array<class-string, class-string> */
    protected function setUpTraits()
    {
        // Pin the owner connection BEFORE RefreshDatabase migrates and opens its
        // transaction, so the schema, the factories and the per-test transaction
        // all share the same identity.
        config(['database.default' => 'pgsql_migration']);

        return parent::setUpTraits();
    }
}
