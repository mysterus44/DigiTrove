<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CrmCommerceRollupRefreshStatus;
use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmCommerceRollupRefreshResult;
use App\Support\CrmConfig;
use Throwable;

/**
 * Thin runtime client over the PostgreSQL process authority. It performs NO financial
 * computation: money is recomputed only inside the P6-A1.1 refresh authority, which the
 * process authority invokes under the executor identity. This class only forwards
 * (contactId, currency) and validates the coordination outcome.
 */
final class CrmCommerceRollupRefreshProcessor
{
    use UsesCrmAuthority;

    public function process(int $contactId, string $currency): CrmCommerceRollupRefreshResult
    {
        if ($contactId < 1 || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw CrmOperationException::unavailable();
        }

        try {
            CrmConfig::assertCommerceRollupRefreshProcessingEnabled();
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.process_crm_commerce_rollup_refresh(?::bigint, ?::varchar)',
                [$contactId, $currency],
            );

            return $this->result($row, $contactId, $currency);
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }

    private function result(?object $row, int $contactId, string $currency): CrmCommerceRollupRefreshResult
    {
        if ($row === null
            || (int) $row->contact_id !== $contactId
            || (string) $row->currency !== $currency) {
            throw CrmOperationException::unavailable();
        }

        $status = CrmCommerceRollupRefreshStatus::tryFrom((string) $row->status);

        if ($status === null) {
            throw CrmOperationException::unavailable();
        }

        return new CrmCommerceRollupRefreshResult(
            $contactId,
            $currency,
            $status,
            $row->requested_generation === null ? null : (int) $row->requested_generation,
            $row->processed_generation === null ? null : (int) $row->processed_generation,
        );
    }
}
