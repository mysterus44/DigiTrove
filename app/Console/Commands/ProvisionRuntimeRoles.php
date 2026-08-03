<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Provisions the cluster-level PostgreSQL boundary roles (D-029.6, D-038).
 *
 * Idempotent convenience wrapper around docker/postgres/provision-runtime-roles.sql,
 * for local development, CI and the phase migration harness. It runs on the
 * MIGRATION connection (an administrative identity able to CREATE ROLE) and feeds
 * the runtime password to the script through a parameterized session GUC, so the
 * secret never lands in a SQL string, in Git, or in the command line of a DDL
 * statement. In production, role creation is a DBA task: run the .sql directly.
 */
class ProvisionRuntimeRoles extends Command
{
    protected $signature = 'db:provision-runtime-roles
        {--connection=pgsql_migration : Administrative connection able to CREATE ROLE}';

    protected $description = 'Provision the restricted runtime and NOLOGIN executor PostgreSQL roles';

    public function handle(): int
    {
        $runtimePassword = (string) config('database.connections.pgsql.password');
        $analyticsWorkerPassword = (string) config('database.connections.pgsql_analytics_worker.password');
        $analyticsReaderPassword = (string) config('database.connections.pgsql_analytics_reader.password');

        if ($runtimePassword === '') {
            $this->error('The runtime connection (pgsql) has no password configured; refusing to provision an empty-password role.');

            return self::FAILURE;
        }

        if ($analyticsWorkerPassword === '') {
            $this->error('The analytics worker connection has no password configured; refusing to provision an empty-password role.');

            return self::FAILURE;
        }

        if ($analyticsReaderPassword === '') {
            $this->error('The analytics reader connection has no password configured; refusing to provision an empty-password role.');

            return self::FAILURE;
        }

        $scriptPath = base_path('docker/postgres/provision-runtime-roles.sql');

        if (! is_file($scriptPath)) {
            $this->error("Provisioning script not found: {$scriptPath}");

            return self::FAILURE;
        }

        // Strip psql meta-commands (\set …) that PDO cannot parse; the SQL body
        // relies only on the session GUC bound below.
        $sql = (string) file_get_contents($scriptPath);
        $sql = preg_replace('/^\s*\\\\.*$/m', '', $sql) ?? $sql;

        $connection = DB::connection($this->option('connection'));

        // Bind the runtime password as a session GUC via a parameterized query;
        // the script reads it with current_setting('digitrove.runtime_password').
        $connection->statement("SELECT set_config('digitrove.runtime_password', ?, false)", [$runtimePassword]);
        $connection->statement("SELECT set_config('digitrove.analytics_worker_password', ?, false)", [$analyticsWorkerPassword]);
        $connection->statement("SELECT set_config('digitrove.analytics_reader_password', ?, false)", [$analyticsReaderPassword]);

        try {
            $connection->getPdo()->exec($sql);
        } finally {
            // Clear the secret from the session as soon as provisioning is done.
            $connection->statement("SELECT set_config('digitrove.runtime_password', '', false)");
            $connection->statement("SELECT set_config('digitrove.analytics_worker_password', '', false)");
            $connection->statement("SELECT set_config('digitrove.analytics_reader_password', '', false)");
        }

        $this->info('Runtime roles provisioned (runtime, executors, analytics worker and analytics reader).');

        return self::SUCCESS;
    }
}
