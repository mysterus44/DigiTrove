<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crm\CrmCommerceRollupBackfillService;
use App\Support\CrmConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * P6-A1.3 operator command. DRY-RUN BY DEFAULT: without `--execute` it only previews
 * candidate pairs and mutates nothing — no run row, no outbox write, no generation
 * bump, no rollup change. Mutating requires BOTH `--execute` AND the
 * CRM_COMMERCE_ROLLUP_BACKFILL_ENABLED flag.
 *
 * There is deliberately no job, scheduler or listener for the backfill: the only
 * asynchronous pipeline remains P6-A1.2, which this command feeds.
 */
final class BackfillCrmCommerceRollups extends Command
{
    protected $signature = 'crm:backfill-commerce-rollups
        {--execute : Actually run the backfill (default is a read-only dry-run)}
        {--run= : Resume an existing durable run by id}
        {--batch-size=50 : Pairs per batch, 1..100}
        {--max-batches=10 : Maximum batches for this invocation, 1..100}
        {--retry-failed : Explicitly return a failed run to ready before processing}';

    protected $description = 'Backfill historical CRM commerce rollups by enqueueing pairs into the durable refresh pipeline (dry-run by default)';

    public function handle(CrmCommerceRollupBackfillService $service): int
    {
        try {
            $batchSize = $this->boundedOption('batch-size');
            $maxBatches = $this->boundedOption('max-batches');
        } catch (Throwable) {
            $this->line('backfill=invalid_options');

            return self::FAILURE;
        }

        try {
            // Operational, non-sensitive: the backfill fills the outbox even when the
            // P6-A1.2 drain is disabled. It never bypasses that separate protection.
            $this->line('rollup_refresh_processing_enabled='.(CrmConfig::commerceRollupRefreshProcessingEnabled() ? '1' : '0'));
        } catch (Throwable) {
            $this->line('rollup_refresh_processing_enabled=0');
        }

        if (! $this->option('execute')) {
            return $this->dryRun($service, $batchSize, $maxBatches);
        }

        return $this->executeBackfill($service, $batchSize, $maxBatches);
    }

    private function dryRun(CrmCommerceRollupBackfillService $service, int $batchSize, int $maxBatches): int
    {
        try {
            $snapshot = $service->currentHighWaterMark();
            $this->line('mode=dry-run');
            $this->line('attribution_order_id_high_water_mark='.$snapshot);

            $previewed = 0;
            $cursorContactId = null;
            $cursorCurrency = null;

            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $pairs = $service->preview($batchSize, $cursorContactId, $cursorCurrency);

                if ($pairs === []) {
                    break;
                }

                $previewed += count($pairs);
                $last = $pairs[array_key_last($pairs)];
                $cursorContactId = $last['contact_id'];
                $cursorCurrency = $last['currency'];
            }

            $this->line('candidate_pairs_previewed='.$previewed);
            $this->line('mutations=0');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('mode=dry-run');
            $this->line('candidate_pairs_previewed=0');
            $this->line('mutations=0');

            return self::FAILURE;
        }
    }

    private function executeBackfill(CrmCommerceRollupBackfillService $service, int $batchSize, int $maxBatches): int
    {
        try {
            CrmConfig::assertCommerceRollupBackfillEnabled();
        } catch (Throwable) {
            $this->line('mode=disabled');
            $this->line('enqueued_pairs=0');

            return self::SUCCESS;
        }

        try {
            $this->line('mode=execute');
            $runId = $this->resolveRunId($service, $batchSize);

            if ($this->option('retry-failed')) {
                $this->line('retry_status='.$service->retry($runId));
            }

            $enqueued = 0;
            $status = 'ready';

            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $result = $service->processBatch($runId);
                $status = $result['status'];
                $enqueued += $result['enqueued_in_batch'];

                if ($status !== 'ready') {
                    break;
                }
            }

            $this->line('run_id='.$runId);
            $this->line('enqueued_pairs='.$enqueued);
            $this->line('status='.$status);

            return $status === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->line('enqueued_pairs=0');
            $this->line('status=unavailable');

            return self::FAILURE;
        }
    }

    private function resolveRunId(CrmCommerceRollupBackfillService $service, int $batchSize): int
    {
        $option = $this->option('run');

        if ($option === null) {
            return $service->start($batchSize)['run_id'];
        }

        if (! is_string($option) || preg_match('/\A[1-9][0-9]*\z/', $option) !== 1) {
            throw new \RuntimeException('invalid run identifier');
        }

        return (int) $option;
    }

    private function boundedOption(string $name): int
    {
        $value = $this->option($name);

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new \RuntimeException('invalid option');
        }

        $int = (int) $value;

        if ($int < 1 || $int > 100) {
            throw new \RuntimeException('option out of range');
        }

        return $int;
    }
}
