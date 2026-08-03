<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\ResolvedCrmContact;
use Illuminate\Support\Str;

final class CrmContactResolver
{
    use UsesCrmAuthority;

    public function resolveGuestOrder(
        #[\SensitiveParameter] string $email,
        int $orderId,
    ): ResolvedCrmContact {
        return $this->resolve($email, 'guest_order', null, $orderId);
    }

    public function resolveVerifiedAccount(
        #[\SensitiveParameter] string $email,
        int $userId,
    ): ResolvedCrmContact {
        return $this->resolve($email, 'verified_account', $userId, null);
    }

    private function resolve(
        #[\SensitiveParameter] string $email,
        string $origin,
        ?int $userId,
        ?int $orderId,
    ): ResolvedCrmContact {
        if (($userId !== null && $userId < 1) || ($orderId !== null && $orderId < 1)) {
            throw CrmOperationException::unavailable();
        }

        $normalised = $this->normaliseEmail($email);

        try {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.resolve_crm_contact(?::varchar, ?::varchar, ?::bigint, ?::bigint)',
                [$normalised, $origin, $userId, $orderId],
            );
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw CrmOperationException::unavailable();
        }

        if ($row === null || (int) $row->contact_id < 1 || ! Str::isUuid($row->public_id)) {
            throw CrmOperationException::unavailable();
        }

        return new ResolvedCrmContact((int) $row->contact_id, strtolower((string) $row->public_id));
    }
}
