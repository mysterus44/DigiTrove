<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateCrmExport;
use App\Services\Crm\CrmExportService;
use App\Support\CrmConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * P6-B1 recovery sweep. It re-dispatches exports that are still `queued` — for instance
 * because the queue was down when they were requested. It is RECOVERY, never a backfill:
 * it creates no export and changes no state itself.
 *
 * The authority read COMPLETES before any dispatch, so no database transaction is held
 * open across a Redis write (the P6-A1.2 dispatcher shape).
 */
final class SweepCrmExports extends Command
{
    protected $signature = 'crm:sweep-exports {--limit=100 : Exports to re-dispatch per run, 1..100}';

    protected $description = 'Re-dispatch queued CRM exports that were never processed';

    public function handle(CrmExportService $exports): int
    {
        try {
            CrmConfig::assertExportProcessingEnabled();
        } catch (Throwable) {
            $this->line('mode=disabled');
            $this->line('dispatched=0');

            return self::SUCCESS;
        }

        try {
            $limit = $this->boundedLimit();
        } catch (Throwable) {
            $this->line('sweep=invalid_options');

            return self::FAILURE;
        }

        try {
            // The read finishes here; the dispatch loop runs afterwards.
            $exportIds = $exports->dueExportIds($limit);
        } catch (Throwable) {
            $this->line('dispatched=0');
            $this->line('status=unavailable');

            return self::FAILURE;
        }

        foreach ($exportIds as $exportId) {
            if ($exportId < 1) {
                $this->line('status=unavailable');

                return self::FAILURE;
            }

            GenerateCrmExport::dispatch($exportId);
        }

        $this->line('mode=execute');
        $this->line('dispatched='.count($exportIds));

        return self::SUCCESS;
    }

    private function boundedLimit(): int
    {
        $value = $this->option('limit');

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
