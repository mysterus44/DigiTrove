<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Crm\CrmExportGenerator;
use App\Services\Crm\CrmExportService;
use App\Support\CrmConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * P6-B1 export job — ID ONLY.
 *
 * The payload is a single integer. No e-mail, no storage path, no file content and no
 * segment definition ever reaches Redis, the `jobs` table, `failed_jobs`, an exception
 * or a log line: everything is re-read from the authorities inside the worker. This is
 * the same discipline as P4-C's SecureDeliveryJob (D-030 Q2).
 */
final class GenerateCrmExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Retry horizon must stay below uniqueFor, otherwise a retry could run while the
     * uniqueness lock has already lapsed and a second worker could claim the same
     * export. tries × (timeout + max backoff) = 3 × (300 + 300) = 1800 < 3600.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $exportId) {}

    public function uniqueId(): string
    {
        return 'crm-export:'.$this->exportId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(CrmExportGenerator $generator): void
    {
        // Fail closed: a disabled pipeline must not process a queued export, even one
        // that was enqueued while the flag was on.
        CrmConfig::assertExportProcessingEnabled();

        $generator->generate($this->exportId);
    }

    /**
     * A terminal job failure must never leave the row stuck in `running`, and must never
     * persist the throwable's message — it can carry driver text or a storage path.
     */
    public function failed(Throwable $exception): void
    {
        try {
            app(CrmExportService::class)
                ->fail($this->exportId, null, 'integrity_failure');
        } catch (Throwable) {
            // The sweeper and the purge both reclaim a stuck row; swallowing here keeps
            // the failure handler itself from throwing.
        }
    }
}
