<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Crm\CrmSegmentService;
use App\Support\CrmConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Materialisation worker. Its payload is ID-only: a generation id, never a definition,
 * a segment name, contact ids, money or e-mail. It evaluates nothing itself — every
 * batch is executed by the PostgreSQL authority.
 *
 * A single run processes at most MAX_BATCHES_PER_JOB batches; the sweeper (or the next
 * dispatch) resumes the durable generation from its cursor. No unbounded loop.
 */
final class ProcessCrmSegmentGeneration implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const MAX_BATCHES_PER_JOB = 10;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $generationId)
    {
        $this->onQueue('crm');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(CrmSegmentService $segments): void
    {
        CrmConfig::assertSegmentRebuildProcessingEnabled();

        for ($batch = 0; $batch < self::MAX_BATCHES_PER_JOB; $batch++) {
            if ($segments->processBatch($this->generationId)['status'] !== 'ready') {
                return;
            }
        }
    }
}
