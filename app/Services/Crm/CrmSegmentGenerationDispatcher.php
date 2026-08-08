<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Jobs\ProcessCrmSegmentGeneration;
use App\Support\CrmConfig;
use Throwable;

/**
 * Bounded recovery sweep: read the due generations through the EXECUTE-only authority,
 * then dispatch one unique job per generation. The database read finishes before any
 * queue write — no DB transaction is held open across the Redis call.
 */
final class CrmSegmentGenerationDispatcher
{
    use Concerns\UsesCrmAuthority;

    public function dispatchDue(): int
    {
        try {
            CrmConfig::assertSegmentRebuildProcessingEnabled();

            // The authority call completes here; the dispatch loop runs afterwards.
            $generationIds = (new CrmSegmentService)->dueGenerationIds(CrmConfig::segmentRebuildBatchSize());

            foreach ($generationIds as $generationId) {
                if ($generationId < 1) {
                    throw CrmOperationException::unavailable();
                }

                ProcessCrmSegmentGeneration::dispatch($generationId);
            }

            return count($generationIds);
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }
}
