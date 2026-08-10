<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crm\CartReminderService;
use Illuminate\Console\Command;
use Throwable;

/**
 * P6-C retention purge. Removes ONLY terminal attempts past the retention window.
 *
 * A pending or claimed attempt is never destroyed: deleting one would silently reset the
 * `(cart, step)` idempotency key and allow the very reminder the ledger exists to
 * prevent from being sent again.
 */
final class PurgeCartReminders extends Command
{
    protected $signature = 'crm:purge-cart-reminders';

    protected $description = 'Purge terminal cart reminder attempts past their retention window';

    public function handle(CartReminderService $reminders): int
    {
        try {
            $purged = $reminders->purge();
        } catch (Throwable) {
            $this->line('mode=disabled');
            $this->line('purged=0');

            return self::SUCCESS;
        }

        $this->line('mode=execute');
        $this->line('purged='.count($purged));

        return self::SUCCESS;
    }
}
