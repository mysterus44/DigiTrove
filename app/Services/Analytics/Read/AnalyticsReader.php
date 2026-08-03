<?php

namespace App\Services\Analytics\Read;

use App\Support\AnalyticsDashboardConfig;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AnalyticsReader
{
    public function run(Closure $query): mixed
    {
        AnalyticsDashboardConfig::assertAvailable();
        $connection = DB::connection(AnalyticsDashboardConfig::CONNECTION);

        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Analytics reader refuses an ambient transaction.');
        }

        if ($connection->scalar('SELECT current_user') !== AnalyticsDashboardConfig::READER_ROLE) {
            throw new RuntimeException('Analytics reader identity mismatch.');
        }

        return $connection->transaction(function (Connection $connection) use ($query): mixed {
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->selectOne("SELECT set_config('statement_timeout', ?, true)", [
                (string) AnalyticsDashboardConfig::statementTimeoutMs(),
            ]);
            $connection->selectOne("SELECT set_config('lock_timeout', ?, true)", [
                (string) AnalyticsDashboardConfig::lockTimeoutMs(),
            ]);

            if ($connection->scalar('SHOW transaction_read_only') !== 'on') {
                throw new RuntimeException('Analytics reader transaction is not read only.');
            }

            return $query($connection);
        });
    }
}
