<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MarketingConsentStatusQuery
{
    use UsesCrmAuthority;

    public function hasCurrentConsent(string $contactPublicId): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw CrmOperationException::unavailable();
        }

        try {
            if (! CrmConfig::enabled() || ! Str::isUuid($contactPublicId)) {
                return false;
            }

            $row = $this->crmConnection()->selectOne(
                'SELECT public.has_current_marketing_consent(?::uuid) AS consent',
                [strtolower($contactPublicId)],
            );

            return (bool) ($row?->consent ?? false);
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw CrmOperationException::unavailable();
        }
    }
}
