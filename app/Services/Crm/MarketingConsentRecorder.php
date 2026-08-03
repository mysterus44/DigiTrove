<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\MarketingConsentAction;
use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmConfig;
use App\Support\RecordedMarketingConsent;
use Illuminate\Support\Str;

final class MarketingConsentRecorder
{
    use UsesCrmAuthority;

    public function grantAtCheckout(
        string $contactPublicId,
        int $orderId,
        #[\SensitiveParameter] string $idempotencyKey,
        ?int $userId = null,
    ): RecordedMarketingConsent {
        return $this->record(
            $contactPublicId,
            MarketingConsentAction::Granted,
            'checkout',
            $userId,
            $orderId,
            $idempotencyKey,
        );
    }

    public function recordAccountSettings(
        string $contactPublicId,
        MarketingConsentAction $action,
        int $userId,
        #[\SensitiveParameter] string $idempotencyKey,
    ): RecordedMarketingConsent {
        return $this->record(
            $contactPublicId,
            $action,
            'account_settings',
            $userId,
            null,
            $idempotencyKey,
        );
    }

    private function record(
        string $contactPublicId,
        MarketingConsentAction $action,
        string $source,
        ?int $userId,
        ?int $orderId,
        #[\SensitiveParameter] string $idempotencyKey,
    ): RecordedMarketingConsent {
        if (! Str::isUuid($contactPublicId)
            || ($userId !== null && $userId < 1)
            || ($orderId !== null && $orderId < 1)) {
            throw CrmOperationException::unavailable();
        }

        try {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.record_crm_marketing_consent(?::uuid, ?::varchar, ?::varchar, ?::bigint, ?::bigint, ?::varchar, ?::varchar)',
                [
                    strtolower($contactPublicId),
                    $action->value,
                    $source,
                    $userId,
                    $orderId,
                    CrmConfig::marketingPolicyVersion(),
                    $this->idempotencyHash($idempotencyKey),
                ],
            );
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw CrmOperationException::unavailable();
        }

        if ($row === null || ! Str::isUuid($row->event_public_id)) {
            throw CrmOperationException::unavailable();
        }

        return new RecordedMarketingConsent(
            strtolower((string) $row->event_public_id),
            (bool) $row->inserted,
        );
    }
}
