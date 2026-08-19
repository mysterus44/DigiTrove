<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Services\Affiliate\Concerns\UsesAffiliateAuthority;

/**
 * P6-D4. The only door into the payout authorities.
 *
 * The PHP side computes NO money: it never sums a balance, never compares a threshold,
 * never decides who is owed what. `list_affiliate_payout_candidates`,
 * `request_affiliate_payout` and `transition_affiliate_payout` do all of it, and the runtime
 * holds `EXECUTE` on them and no DML anywhere in the affiliate block — not even `SELECT`.
 *
 * ⚠️ A PAYOUT MOVES NO MONEY HERE. There is no provider and no transfer: marking a payout
 * `paid` records that a payment happened ELSEWHERE, against an administrative reference.
 */
final class AffiliatePayoutService
{
    use UsesAffiliateAuthority;

    /** @return list<object> one row per (affiliate, currency) with a positive payable balance. */
    public function candidates(int $limit = 100): array
    {
        return $this->affiliateConnection()->select(
            'SELECT * FROM public.list_affiliate_payout_candidates(?)',
            [$limit],
        );
    }

    /** @return list<object> */
    public function payouts(?string $status = null, int $limit = 100): array
    {
        return $this->affiliateConnection()->select(
            'SELECT * FROM public.list_affiliate_payouts(?, ?)',
            [$status, $limit],
        );
    }

    /** @return list<object> */
    public function items(int $payoutId): array
    {
        return $this->affiliateConnection()->select(
            'SELECT * FROM public.list_affiliate_payout_items(?)',
            [$payoutId],
        );
    }

    /**
     * @return array{status: string, payout_id: int|null} one of `requested`, `nothing_payable`,
     *                                                    `below_threshold`, `unsupported_currency`,
     *                                                    `no_active_policy`, `no_such_affiliate`
     */
    public function request(int $affiliateId, string $currency, int $actorUserId): array
    {
        $connection = $this->affiliateConnection();

        try {
            $row = $connection->selectOne(
                'SELECT * FROM public.request_affiliate_payout(?, ?, ?)',
                [$affiliateId, $currency, $actorUserId],
            );
        } catch (\Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }

        return [
            'status' => (string) $row->payout_status,
            'payout_id' => $row->payout_id === null ? null : (int) $row->payout_id,
        ];
    }

    /**
     * @param  string  $expectedStatus  the status the administrator was LOOKING AT. A stale
     *                                  value raises `AF002` rather than silently overwriting
     *                                  a colleague's decision — the same compare-and-swap
     *                                  discipline `AffiliateLifecycle` uses for code rotation.
     * @return string one of `transitioned`, `illegal_transition`, `no_such_payout`,
     *                `missing_administrative_reference`, `same_administrator_forbidden`
     */
    public function transition(
        int $payoutId,
        string $expectedStatus,
        string $targetStatus,
        int $actorUserId,
        ?string $administrativeReference = null,
    ): string {
        $connection = $this->affiliateConnection();

        try {
            return (string) $connection->selectOne(
                'SELECT public.transition_affiliate_payout(?, ?, ?, ?, ?) AS status',
                [$payoutId, $expectedStatus, $targetStatus, $actorUserId, $administrativeReference],
            )->status;
        } catch (\Throwable $exception) {
            throw $this->affiliateRefusal($exception);
        }
    }
}
