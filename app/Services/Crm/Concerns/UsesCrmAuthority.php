<?php

declare(strict_types=1);

namespace App\Services\Crm\Concerns;

use App\Services\Crm\CrmOperationException;
use App\Support\CrmConfig;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

trait UsesCrmAuthority
{
    private function crmConnection(): Connection
    {
        if (DB::transactionLevel() !== 0) {
            throw CrmOperationException::unavailable();
        }

        try {
            CrmConfig::assertEnabled();
            $connection = DB::connection();
            $identity = $connection->selectOne('SELECT session_user, current_user');

            if ($identity === null
                || $identity->session_user !== 'digitrove_runtime'
                || $identity->current_user !== 'digitrove_runtime') {
                throw CrmOperationException::unavailable();
            }

            return $connection;
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw CrmOperationException::unavailable();
        }
    }

    private function normaliseEmail(#[\SensitiveParameter] string $email): string
    {
        $normalised = strtolower(trim($email));

        if (strlen($normalised) > 254
            || filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
            throw CrmOperationException::unavailable();
        }

        return $normalised;
    }

    private function idempotencyHash(#[\SensitiveParameter] string $key): string
    {
        $length = strlen($key);

        if ($length < 16 || $length > 255 || trim($key) === '') {
            throw CrmOperationException::unavailable();
        }

        return hash('sha256', $key);
    }
}
