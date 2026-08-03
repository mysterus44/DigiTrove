<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CrmOrderAttributionReason;
use App\Enums\CrmOrderAttributionSource;
use App\Enums\CrmOrderAttributionStatus;
use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmConfig;
use App\Support\CrmOrderAttributionResult;
use Illuminate\Support\Str;
use Throwable;

final class CrmOrderAttributionProcessor
{
    use UsesCrmAuthority;

    public function process(int $orderId): CrmOrderAttributionResult
    {
        if ($orderId < 1) {
            throw CrmOperationException::unavailable();
        }

        try {
            CrmConfig::assertOrderAttributionProcessingEnabled();
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.process_crm_order_attribution(?::bigint)',
                [$orderId],
            );

            return $this->result($row, $orderId);
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }

    private function result(?object $row, int $orderId): CrmOrderAttributionResult
    {
        if ($row === null || (int) $row->order_id !== $orderId) {
            throw CrmOperationException::unavailable();
        }

        $status = CrmOrderAttributionStatus::tryFrom((string) $row->status);
        $reason = $row->reason === null
            ? null
            : CrmOrderAttributionReason::tryFrom((string) $row->reason);
        $source = $row->source === null
            ? null
            : CrmOrderAttributionSource::tryFrom((string) $row->source);
        $contactPublicId = $row->contact_public_id === null
            ? null
            : strtolower((string) $row->contact_public_id);

        $validAttributed = $status === CrmOrderAttributionStatus::Attributed
            && $reason === null
            && $source !== null
            && is_string($contactPublicId)
            && Str::isUuid($contactPublicId);
        $validUnattributable = $status === CrmOrderAttributionStatus::Unattributable
            && $reason !== null
            && $source === null
            && $contactPublicId === null;

        if (! $validAttributed && ! $validUnattributable) {
            throw CrmOperationException::unavailable();
        }

        return new CrmOrderAttributionResult($orderId, $status, $reason, $contactPublicId, $source);
    }
}
