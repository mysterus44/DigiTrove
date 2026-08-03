<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Jobs\ProcessCrmOrderAttribution;
use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmConfig;
use Throwable;

final class CrmOrderAttributionDispatcher
{
    use UsesCrmAuthority;

    public function dispatchDue(): int
    {
        try {
            CrmConfig::assertOrderAttributionProcessingEnabled();
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_due_crm_order_attributions(?::integer)',
                [CrmConfig::orderAttributionBatchSize()],
            );

            foreach ($rows as $row) {
                $orderId = (int) ($row->order_id ?? 0);

                if ($orderId < 1) {
                    throw CrmOperationException::unavailable();
                }

                ProcessCrmOrderAttribution::dispatch($orderId);
            }

            return count($rows);
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }
}
