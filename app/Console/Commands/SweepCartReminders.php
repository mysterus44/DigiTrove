<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendCartReminder;
use App\Services\Crm\CartReminderService;
use App\Support\CartReminderConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * P6-C recovery sweep. Re-dispatches attempts still `pending` — for instance because the
 * queue was down when they were created.
 *
 * It is RECOVERY, never a backfill: it creates no attempt, reopens no terminal state and
 * cannot bypass the cap or the cooldown, because it only ever re-dispatches an attempt
 * that already exists and is still pending.
 *
 * The authority read completes BEFORE any dispatch, so no transaction is held open
 * across a Redis write.
 */
final class SweepCartReminders extends Command
{
    protected $signature = 'crm:sweep-cart-reminders';

    protected $description = 'Re-dispatch pending cart reminder attempts that were never processed';

    public function handle(CartReminderService $reminders): int
    {
        try {
            CartReminderConfig::assertSendEnabled();
        } catch (Throwable) {
            $this->line('mode=disabled');
            $this->line('dispatched=0');

            return self::SUCCESS;
        }

        try {
            $attemptIds = $reminders->dueAttemptIds();
        } catch (Throwable) {
            $this->line('dispatched=0');
            $this->line('status=unavailable');

            return self::FAILURE;
        }

        foreach ($attemptIds as $attemptId) {
            SendCartReminder::dispatch($attemptId);
        }

        $this->line('mode=execute');
        $this->line('dispatched='.count($attemptIds));

        return self::SUCCESS;
    }
}
