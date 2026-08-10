<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Cart\CartReminderDispatcher;
use App\Services\Cart\CartReminderService;
use App\Support\CartReminderConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * P6-C reminder worker — ID ONLY.
 *
 * The payload is a single integer. No address, no capability, no cart content, no link
 * and no provider response ever reaches Redis, the `jobs` table, `failed_jobs`, a log or
 * a serialised exception: everything is re-read from the authorities inside the worker,
 * and the capability is minted there and dies with the process.
 */
final class SendCartReminder implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Must exceed the retry horizon: tries × (timeout + max backoff) =
     * 3 × (120 + 300) = 1260 < 1800, so a retry can never run while the uniqueness lock
     * has lapsed and let a second worker claim the same attempt.
     */
    public int $uniqueFor = 1800;

    public function __construct(public readonly int $attemptId) {}

    public function uniqueId(): string
    {
        return 'cart-reminder:'.$this->attemptId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(CartReminderDispatcher $dispatcher): void
    {
        // Fail closed: a disabled pipeline must not process an attempt that was queued
        // while the flag was still on.
        CartReminderConfig::assertSendEnabled();

        $dispatcher->dispatch($this->attemptId);
    }

    /**
     * A terminal job failure must never leave the attempt stuck in `claimed`, and must
     * never persist the throwable — it can quote the recipient or the provider response.
     */
    public function failed(Throwable $exception): void
    {
        try {
            app(CartReminderService::class)->fail($this->attemptId, null);
        } catch (Throwable) {
            // The sweeper reclaims a stuck attempt; swallowing here keeps the failure
            // handler from throwing inside the queue worker.
        }
    }
}
