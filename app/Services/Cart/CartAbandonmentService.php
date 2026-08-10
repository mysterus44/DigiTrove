<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Services\Crm\CrmOperationException;
use App\Support\CartReminderConfig;
use Throwable;

/**
 * P6-C abandonment detection (D-056 §1, §11).
 *
 * The transition `active → abandoned` is decided by PostgreSQL, on
 * `carts.last_activity_at` — never on `updated_at`, which `CartItem` does not touch and
 * which this very transition would otherwise move, making a cart look active because it
 * was just marked abandoned.
 *
 * The authority is bounded, keyset-ordered and uses `FOR UPDATE SKIP LOCKED`, so two
 * detectors running at once split the work instead of fighting over it, and a re-run
 * finds nothing new.
 */
final class CartAbandonmentService
{
    use UsesCrmAuthority;

    /**
     * Mark one bounded batch of inactive carts as abandoned.
     *
     * @return list<int> the cart ids that transitioned in THIS call
     */
    public function detect(): array
    {
        CartReminderConfig::assertDetectionEnabled();

        // Bounds are read BEFORE the call: an unconfigured cadence must refuse rather
        // than fall back to a window this codebase invented.
        $minutes = CartReminderConfig::inactivityMinutes();
        $limit = CartReminderConfig::batchSize();

        try {
            $rows = $this->crmConnection()->select(
                'SELECT cart_id FROM public.mark_abandoned_carts(?::integer, ?::integer)',
                [$minutes, $limit],
            );
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }

        return array_map(static fn (object $row): int => (int) $row->cart_id, $rows);
    }
}
