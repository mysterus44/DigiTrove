<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendCartReminder;
use App\Services\Crm\CartReminderService;
use App\Support\CartReminderConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * P6-C enqueue. Turns eligible abandoned carts into reminder attempts, one step at a
 * time, and dispatches the worker only when sending is separately enabled.
 *
 * Enqueuing does NOT promise an e-mail: every decisive check is redone by the worker at
 * send time, because the window between the two is measured in hours.
 *
 * The authority read completes BEFORE any dispatch, so no database transaction is ever
 * held open across a Redis write.
 */
final class EnqueueCartReminders extends Command
{
    protected $signature = 'crm:enqueue-cart-reminders {--step=1 : The reminder step to enqueue}';

    protected $description = 'Create reminder attempts for eligible abandoned carts';

    public function handle(CartReminderService $reminders): int
    {
        try {
            CartReminderConfig::assertEnqueueEnabled();
            $step = $this->boundedStep();
        } catch (Throwable) {
            $this->line('mode=disabled');
            $this->line('queued=0');

            return self::SUCCESS;
        }

        try {
            // Keyset, bounded, no OFFSET. Identity is filtered by the authority itself.
            $candidates = $reminders->candidates(null, $step);
        } catch (Throwable) {
            $this->line('queued=0');
            $this->line('status=unavailable');

            return self::FAILURE;
        }

        $queued = 0;
        $dispatched = 0;
        $sendEnabled = $this->sendEnabled();

        foreach ($candidates as $candidate) {
            try {
                $attempt = $reminders->enqueue($candidate['cart_id'], $step);
            } catch (Throwable) {
                continue;
            }

            if (! $attempt['created']) {
                // Already queued for this step: idempotent, nothing to report.
                continue;
            }

            $queued++;

            // The attempt is durable either way; dispatching only makes it run sooner.
            if ($sendEnabled) {
                SendCartReminder::dispatch($attempt['attempt_id']);
                $dispatched++;
            }
        }

        $this->line('mode=execute');
        $this->line('queued='.$queued);
        $this->line('dispatched='.$dispatched);

        return self::SUCCESS;
    }

    private function sendEnabled(): bool
    {
        try {
            CartReminderConfig::assertSendEnabled();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function boundedStep(): int
    {
        $value = $this->option('step');

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new \RuntimeException('invalid step');
        }

        $step = (int) $value;

        // The attempt cap lives in configuration, and a step beyond it is refused rather
        // than silently clamped.
        if ($step < 1 || $step > CartReminderConfig::maxStep()) {
            throw new \RuntimeException('step out of range');
        }

        return $step;
    }
}
