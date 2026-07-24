<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GrantRevocationReason;
use App\Mail\OrderDownloadsReady;
use App\Services\Delivery\GrantIssuanceService;
use App\Support\DeliveryConfig;
use App\Support\IssuedGrantBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Delivers a paid order's downloads securely, once, in the worker (P4-C3, D-035).
 *
 * The serialised payload is EXACTLY `orderId` — no token, no e-mail, no
 * public id, no grant list, no model. Tokens are generated inside the worker,
 * the e-mail is sent synchronously in the same process, and any failure revokes
 * the just-issued grants and rethrows for an at-least-once retry.
 */
final class SecureDeliveryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly int $orderId)
    {
        $this->afterCommit();
    }

    /** One live delivery per order. */
    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function uniqueFor(): int
    {
        return DeliveryConfig::jobUniqueSeconds();
    }

    /** @return list<int> bounded, growing backoff between retries (seconds). */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(GrantIssuanceService $issuer): void
    {
        // The grant issuance opens its own transaction and the mail is sent
        // outside any transaction: the worker must start at level 0.
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Secure delivery must run outside a transaction.');
        }

        DeliveryConfig::assertPipelineReady();

        $batch = $issuer->issueForOrder($this->orderId);

        if ($batch->isEmpty()) {
            return;
        }

        $this->deliver($issuer, $batch);
    }

    private function deliver(GrantIssuanceService $issuer, IssuedGrantBatch $batch): void
    {
        try {
            // Synchronous send in the worker; the Mailable is NEVER queued.
            Mail::to($batch->customerEmail)->send(new OrderDownloadsReady($batch));
        } catch (Throwable) {
            // Delivery failed: revoke the grants we just issued so no orphan
            // active grant survives with a token that was only ever in memory.
            try {
                $issuer->revokeIssuedBatch($batch, GrantRevocationReason::DeliveryFailed);
            } catch (Throwable) {
                throw new RuntimeException('Secure delivery failed and its grants could not be revoked.');
            }

            // Rethrow sanitised (no token, no address) to let the queue retry.
            throw new RuntimeException('Secure delivery could not be completed.');
        }
    }
}
