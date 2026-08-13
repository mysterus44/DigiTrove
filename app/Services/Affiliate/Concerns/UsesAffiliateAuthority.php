<?php

declare(strict_types=1);

namespace App\Services\Affiliate\Concerns;

use App\Services\Affiliate\AffiliateOperationException;
use App\Services\Affiliate\AffiliateRefusalReason;
use App\Support\AffiliateConfig;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only door into the affiliate schema, mirroring `UsesCrmAuthority`.
 *
 * Two guarantees matter here. First, the runtime identity is asserted rather than
 * assumed: if the connection is not the restricted role, the operation refuses instead of
 * silently running with more power than it should have. Second, an ambient transaction is
 * refused — a bounded affiliate authority must own its own transactional boundary,
 * so a caller cannot widen it and leave a half-published timeline hanging on someone
 * else's rollback.
 */
trait UsesAffiliateAuthority
{
    private function affiliateConnection(): Connection
    {
        if (DB::transactionLevel() !== 0) {
            throw AffiliateOperationException::because(AffiliateRefusalReason::Unavailable);
        }

        try {
            AffiliateConfig::assertGovernanceEnabled();

            $connection = DB::connection();
            $identity = $connection->selectOne('SELECT session_user, current_user');

            if ($identity === null
                || $identity->session_user !== 'digitrove_runtime'
                || $identity->current_user !== 'digitrove_runtime') {
                throw AffiliateOperationException::because(AffiliateRefusalReason::Unavailable);
            }

            return $connection;
        } catch (AffiliateOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw AffiliateOperationException::because(AffiliateRefusalReason::Unavailable);
        }
    }

    /**
     * Translate a database failure into a typed refusal.
     *
     * The SQLSTATE is the ONLY signal used. A message substring would be forgeable, which
     * is exactly the defect D-030.2.1 closed for `OrderService`; and the raw message would
     * carry constraint names outward. Anything unrecognised degrades to `Unavailable`
     * rather than being guessed at.
     */
    private function affiliateRefusal(Throwable $exception): AffiliateOperationException
    {
        if ($exception instanceof AffiliateOperationException) {
            return $exception;
        }

        $sqlState = null;

        if ($exception instanceof QueryException) {
            $sqlState = is_array($exception->errorInfo) ? ($exception->errorInfo[0] ?? null) : null;
        }

        return AffiliateOperationException::because(match ($sqlState) {
            '22023' => AffiliateRefusalReason::InvalidInput,
            '23514' => AffiliateRefusalReason::NotADraft,
            '23505' => AffiliateRefusalReason::VersionTaken,
            '40001' => AffiliateRefusalReason::ConcurrentPublication,
            // A DigiTrove-specific code. It exists precisely so that a stale rotation is
            // not mistaken for a concurrent publication — both would otherwise arrive as
            // `40001` and the administrator would read a message about policies while
            // rotating a code.
            'AF001' => AffiliateRefusalReason::StaleCodeRotation,
            default => AffiliateRefusalReason::Unavailable,
        });
    }
}
