<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\AnalyticsOperationsConfig;
use App\Support\AnalyticsRollupResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AuthoritativeRollupService
{
    public function refresh(CarbonImmutable $day): AnalyticsRollupResult
    {
        AnalyticsOperationsConfig::assertRollupsEnabled();
        $this->assertNoAmbientTransaction();

        $connection = DB::connection('pgsql_analytics_worker');
        $this->assertWorkerIdentity($connection);
        $lockKey = 'digitrove:analytics-rollup:'.$day->utc()->toDateString();
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

            return $connection->transaction(function () use ($connection, $day): AnalyticsRollupResult {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->applyLocalTimeouts($connection);

                $row = $connection->selectOne(
                    'SELECT public.refresh_authoritative_daily_analytics(?::date)::text AS result',
                    [$day->utc()->toDateString()],
                );

                if ($row === null || ! is_string($row->result)) {
                    throw new RuntimeException('The authoritative analytics rollup returned no result.');
                }

                return AnalyticsRollupResult::fromDatabaseJson($row->result);
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
