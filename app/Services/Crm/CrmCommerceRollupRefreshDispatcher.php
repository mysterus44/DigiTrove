<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Jobs\ProcessCrmCommerceRollupRefresh;
use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmConfig;
use Throwable;

/**
 * Bounded recovery sweep: read the durable due work via the EXECUTE-only list_due
 * authority and dispatch one unique job per (contact, currency). This is the P6-A1.2
 * reconciliation — it only re-drives the durable outbox. It performs NO historical
 * backfill of orders/refunds/attributions (that is P6-A1.3), and never SELECTs the
 * outbox directly nor holds a DB transaction open across the queue dispatch.
 */
final class CrmCommerceRollupRefreshDispatcher
{
    use UsesCrmAuthority;

    public function dispatchDue(): int
    {
        try {
            CrmConfig::assertCommerceRollupRefreshProcessingEnabled();
            $rows = $this->crmConnection()->select(
                'SELECT contact_id, currency FROM public.list_due_crm_commerce_rollup_refreshes(?::integer)',
                [CrmConfig::commerceRollupRefreshBatchSize()],
            );

            foreach ($rows as $row) {
                $contactId = (int) ($row->contact_id ?? 0);
                $currency = (string) ($row->currency ?? '');

                if ($contactId < 1 || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
                    throw CrmOperationException::unavailable();
                }

                ProcessCrmCommerceRollupRefresh::dispatch($contactId, $currency);
            }

            return count($rows);
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }
}
