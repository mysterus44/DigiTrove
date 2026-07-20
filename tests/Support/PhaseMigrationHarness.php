<?php

namespace Tests\Support;

use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Boundary-isolated migration harness for phase rollback tests.
 *
 * It applies ONLY the migrations up to and including a phase boundary onto a dedicated
 * throwaway PostgreSQL database, then rolls back ONLY the explicit gate migrations by
 * path. Migrations living after the boundary are never applied, so a rollback test can
 * prove a phase's up()/down() in isolation regardless of how many later phases exist —
 * no reliance on migrate:fresh, --step, or the global migration count/order.
 */
final class PhaseMigrationHarness
{
    /** @var array<string, mixed> */
    private array $connection;

    /** @var array<string, mixed> */
    private array $runtimeConnection;

    private PDO $admin;

    private ?PDO $connectionPdo = null;

    private ?PDO $runtimePdoInstance = null;

    public function __construct(private readonly string $databaseName)
    {
        // P4-B0 (D-029.6): the harness administers and migrates the throwaway
        // database with the migrator/owner identity, and exposes a separate
        // runtime PDO so boundary probes run under the real restricted role.
        $this->connection = config('database.connections.pgsql_migration');
        $this->runtimeConnection = config('database.connections.pgsql');
        $this->admin = $this->makePdo('postgres');
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function create(): void
    {
        $quoted = '"'.$this->databaseName.'"';
        $this->admin->exec("DROP DATABASE IF EXISTS {$quoted} WITH (FORCE)");
        $this->admin->exec("CREATE DATABASE {$quoted}");
    }

    public function drop(): void
    {
        $this->connectionPdo = null;
        $this->runtimePdoInstance = null;
        $quoted = '"'.$this->databaseName.'"';
        $this->admin->exec("DROP DATABASE IF EXISTS {$quoted} WITH (FORCE)");
    }

    /**
     * Apply every migration from the start of the project up to and including the
     * boundary file. Refuses if the boundary is unknown or if a later migration would
     * end up applied. Returns the migration names actually recorded, in order.
     *
     * @return list<string>
     */
    public function applyMigrationsThrough(string $boundaryFile): array
    {
        $all = $this->allMigrationFiles();
        $index = array_search($boundaryFile, $all, true);

        if ($index === false) {
            throw new RuntimeException("Unknown boundary migration: {$boundaryFile}");
        }

        $throughBoundary = array_slice($all, 0, $index + 1);
        $paths = array_map(
            static fn (string $file): string => '--path=database/migrations/'.$file,
            $throughBoundary,
        );

        $this->artisan(['migrate', '--env=testing', '--force', '--database=pgsql_migration', ...$paths]);

        $applied = $this->ranMigrations();
        $boundaryName = $this->migrationName($boundaryFile);

        foreach ($applied as $name) {
            if ($name > $boundaryName) {
                throw new RuntimeException("A migration after the boundary was applied: {$name}");
            }
        }

        return $applied;
    }

    /**
     * Roll back ONLY the given gate migrations (by path). Returns the migration names
     * whose down() actually ran, computed from the migrations table before/after.
     *
     * @param  list<string>  $gateFiles
     * @return list<string>
     */
    public function rollbackExactMigrations(array $gateFiles): array
    {
        $before = $this->ranMigrations();

        foreach ($gateFiles as $file) {
            if (! in_array($this->migrationName($file), $before, true)) {
                throw new RuntimeException("Gate migration was not applied before rollback: {$file}");
            }
        }

        $paths = array_map(
            static fn (string $file): string => '--path=database/migrations/'.$file,
            $gateFiles,
        );

        $this->artisan(['migrate:rollback', '--env=testing', '--force', '--database=pgsql_migration', ...$paths]);

        return array_values(array_diff($before, $this->ranMigrations()));
    }

    public function currentDatabase(): string
    {
        return (string) $this->pdo()->query('SELECT current_database()')->fetchColumn();
    }

    public function hasTable(string $table): bool
    {
        return $this->pdo()
            ->query("SELECT to_regclass('public.".$table."')")
            ->fetchColumn() !== null;
    }

    public function hasConstraint(string $name): bool
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM pg_constraint WHERE conname = :name');
        $statement->execute(['name' => $name]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param  list<string>  $names
     */
    public function countFunctions(array $names): int
    {
        return $this->countIn('pg_proc', 'proname', $names);
    }

    /**
     * @param  list<string>  $names
     */
    public function countTriggers(array $names): int
    {
        return $this->countIn('pg_trigger', 'tgname', $names);
    }

    /**
     * @return list<string>
     */
    public function ranMigrations(): array
    {
        return array_map(
            static fn ($value): string => (string) $value,
            $this->pdo()->query('SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /**
     * @param  list<string>  $names
     */
    private function countIn(string $catalog, string $column, array $names): int
    {
        if ($names === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $statement = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM {$catalog} WHERE {$column} IN ({$placeholders})",
        );
        $statement->execute(array_values($names));

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function allMigrationFiles(): array
    {
        $files = array_map('basename', glob(database_path('migrations').'/*.php') ?: []);
        sort($files);

        return array_values($files);
    }

    private function migrationName(string $file): string
    {
        return (string) preg_replace('/\.php$/', '', $file);
    }

    private function pdo(): PDO
    {
        return $this->connectionPdo ??= $this->makePdo($this->databaseName);
    }

    /**
     * A connection to the throwaway database authenticated as the RESTRICTED
     * runtime role (P4-B0, D-029.6). Boundary probes must use this — never the
     * owner PDO — so an ACL regression cannot hide behind the migrator.
     */
    public function runtimePdo(): PDO
    {
        return $this->runtimePdoInstance ??= new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $this->runtimeConnection['host'],
                $this->runtimeConnection['port'] ?? 5432,
                $this->databaseName,
            ),
            $this->runtimeConnection['username'],
            $this->runtimeConnection['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function makePdo(string $database): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $this->connection['host'],
            $this->connection['port'] ?? 5432,
            $database,
        );

        return new PDO(
            $dsn,
            $this->connection['username'],
            $this->connection['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * @param  list<string>  $arguments
     */
    private function artisan(array $arguments): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', ...$arguments],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => $this->databaseName,
                // P4-B0: migrations run under the migrator/owner identity; the
                // default runtime identity is passed through unchanged so the
                // subprocess resolves both connections exactly like the suite.
                'DB_USERNAME' => (string) $this->runtimeConnection['username'],
                'DB_PASSWORD' => (string) $this->runtimeConnection['password'],
                'DB_MIGRATION_USERNAME' => (string) $this->connection['username'],
                'DB_MIGRATION_PASSWORD' => (string) $this->connection['password'],
            ],
        );
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'artisan '.implode(' ', $arguments)." failed:\n".$process->getOutput().$process->getErrorOutput(),
            );
        }
    }
}
