<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\AnalyticsOperationsConfig;
use App\Support\EventPartitionResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class EventPartitionService
{
    public function ensure(CarbonImmutable $month): EventPartitionResult
    {
        AnalyticsOperationsConfig::assertPartitionsEnabled();
        $this->assertNoAmbientTransaction();

        $connection = DB::connection('pgsql_analytics_worker');
        $this->assertWorkerIdentity($connection);
        $normalized = $month->utc()->startOfMonth();
        $lockKey = 'digitrove:events-partition:'.$normalized->toDateString();
        $previousStatementTimeout = $connection->selectOne(
            "SELECT current_setting('statement_timeout') AS value",
        )->value;
        $locked = false;
        $connection->selectOne(
            "SELECT set_config('statement_timeout', ?, false)",
            [AnalyticsOperationsConfig::statementTimeoutMilliseconds().'ms'],
        );

        try {
            $connection->selectOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$lockKey]);
            $locked = true;

            return $connection->transaction(function () use ($connection, $normalized): EventPartitionResult {
                $this->applyLocalTimeouts($connection);
                $row = $connection->selectOne(
                    'SELECT public.ensure_analytics_events_month_partition(?::date)::text AS result',
                    [$normalized->toDateString()],
                );

                if ($row === null || ! is_string($row->result)) {
                    throw new RuntimeException('The analytics partition authority returned no result.');
                }

                return EventPartitionResult::fromDatabaseJson($row->result);
            });
        } finally {
            if ($locked) {
                $connection->selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lockKey]);
            }

            $connection->selectOne(
                "SELECT set_config('statement_timeout', ?, false)",
                [$previousStatementTimeout],
            );
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function audit(): array
    {
        AnalyticsOperationsConfig::assertPartitionsEnabled();
        $this->assertNoAmbientTransaction();

        $connection = DB::connection('pgsql_analytics_worker');
        $this->assertWorkerIdentity($connection);

        return $connection->transaction(function () use ($connection): array {
            $this->applyLocalTimeouts($connection);
            $row = $connection->selectOne('SELECT public.audit_analytics_event_partitions()::text AS result');

            if ($row === null || ! is_string($row->result)) {
                throw new RuntimeException('The analytics partition audit returned no result.');
            }

            return json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function assertNoAmbientTransaction(): void
    {
        foreach (['pgsql', 'pgsql_migration', 'pgsql_analytics_worker'] as $name) {
            if (DB::connection($name)->transactionLevel() !== 0) {
                throw new RuntimeException('Analytics operations refuse ambient database transactions.');
            }
        }
    }

    private function assertWorkerIdentity(Connection $connection): void
    {
        $identity = $connection->selectOne('SELECT current_user AS current_role, session_user AS session_role');

        if ($identity === null
            || $identity->current_role !== 'digitrove_analytics_worker'
            || $identity->session_role !== 'digitrove_analytics_worker') {
            throw new RuntimeException('Analytics operations require the dedicated worker database identity.');
        }
    }

    private function applyLocalTimeouts(Connection $connection): void
    {
        $connection->selectOne(
            "SELECT set_config('statement_timeout', ?, true), set_config('lock_timeout', ?, true)",
            [
                AnalyticsOperationsConfig::statementTimeoutMilliseconds().'ms',
                AnalyticsOperationsConfig::lockTimeoutMilliseconds().'ms',
            ],
        );
    }
}
