<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\WebhookProcessingStatus;
use App\Support\WebhookReconciliationConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Closes out signed webhooks that never reached a financial decision (H1, dette #6).
 *
 * ⚠️ IT NEVER RETRIES ANYTHING WITH THE PROVIDER. Retrying the counter-call automatically
 * would change a CinetPay behaviour already proven by an existing test
 * (`it mutates nothing and fails the event when the counter-call times out`). This service
 * makes the problem VISIBLE AND BOUNDED IN TIME; it does not resolve it. Whoever eventually
 * builds the retry does so as its own decision, not as a side effect of this one.
 *
 * ⚠️ SIGNED EVENTS ONLY. An event whose signature never verified is constrained by
 * `payment_webhook_events_invalid_minimal_shape_check` to stay `failed`, and rightly so: it
 * did not "fail to be processed", it was DELIBERATELY REJECTED — the same category as
 * `processed` and `ignored`, not the same as a counter-call that died. The scope is narrowed
 * here rather than the CHECK being widened.
 *
 * Two windows, two different jobs:
 *
 *   ESCALATION — an event unresolved past the threshold is logged at `critical`. This is
 *   the part that matters most while no retry mechanism exists: the expiry closes a record,
 *   the alert is what actually reaches a human in time to act.
 *
 *   EXPIRY — an event unresolved past the window becomes `unresolved_expired`, carrying its
 *   provenance so `received` (never resolved) stays distinguishable from `failed` (provider
 *   unreachable).
 */
final class WebhookReconciliationService
{
    /**
     * A bound, not a policy. It keeps one run from holding a long transaction over an
     * unbounded set; the sweep simply resumes on the next invocation.
     */
    public const BATCH_SIZE = 500;

    /**
     * @return array{escalated: int, expired: int}
     */
    public function reconcile(bool $execute, ?CarbonImmutable $at = null): array
    {
        // Validated BEFORE a single row is read: an invalid bound fails the run, never
        // halfway through it.
        WebhookReconciliationConfig::assertReady();

        $now = $at ?? CarbonImmutable::now();
        $escalateBefore = $now->subMinutes(WebhookReconciliationConfig::escalationMinutes());
        $expireBefore = $now->subHours(WebhookReconciliationConfig::expiryHours());

        $expired = $this->expire($expireBefore, $now, $execute);
        // Escalation runs AFTER expiry so a row closed out in this same pass is not also
        // alerted on — an operator paged about something already terminal learns nothing.
        $escalated = $this->escalate($escalateBefore, $expireBefore);

        return ['escalated' => $escalated, 'expired' => $expired];
    }

    /**
     * Rows past the expiry window, closed out with their provenance.
     *
     * Each row is updated on its own so one refusal cannot discard the rest: a single
     * malformed row must not stop a sweep that is protecting real money.
     */
    private function expire(CarbonImmutable $expireBefore, CarbonImmutable $now, bool $execute): int
    {
        $rows = $this->unresolved()
            ->where('received_at', '<', $expireBefore)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'processing_status']);

        if (! $execute) {
            return $rows->count();
        }

        $count = 0;

        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $now, &$count): void {
                $affected = DB::table('payment_webhook_events')
                    ->where('id', $row->id)
                    // Re-checked inside the transaction: a live webhook may have resolved
                    // the row between the read and the write, and closing THAT out would
                    // overwrite a real decision.
                    ->where('processing_status', $row->processing_status)
                    ->update([
                        'processing_status' => WebhookProcessingStatus::UnresolvedExpired->value,
                        'expired_at' => $now,
                        'expired_from_status' => $row->processing_status,
                        'updated_at' => $now,
                    ]);

                $count += $affected;
            });
        }

        return $count;
    }

    /**
     * Rows past the escalation threshold but not yet expired.
     *
     * ⚠️ The message carries IDs and counts only — no payload, no provider reference, no
     * payment id. An alert is read in a log aggregator by people who have no business seeing
     * financial identifiers, and `critical` messages get forwarded further than most.
     */
    private function escalate(CarbonImmutable $escalateBefore, CarbonImmutable $expireBefore): int
    {
        $rows = $this->unresolved()
            ->where('received_at', '<', $escalateBefore)
            ->where('received_at', '>=', $expireBefore)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'processing_status']);

        if ($rows->isEmpty()) {
            return 0;
        }

        Log::critical('Signed payment webhooks are unresolved past the escalation threshold.', [
            'count' => $rows->count(),
            'event_ids' => $rows->pluck('id')->all(),
            'by_status' => $rows->countBy('processing_status')->all(),
            'threshold_minutes' => WebhookReconciliationConfig::escalationMinutes(),
        ]);

        return $rows->count();
    }

    /** Signed events still awaiting a financial decision. */
    private function unresolved(): Builder
    {
        return DB::table('payment_webhook_events')
            ->where('signature_verified', true)
            ->whereIn('processing_status', [
                WebhookProcessingStatus::Received->value,
                WebhookProcessingStatus::Failed->value,
            ]);
    }
}
