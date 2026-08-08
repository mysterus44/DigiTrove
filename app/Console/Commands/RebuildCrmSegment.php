<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessCrmSegmentGeneration;
use App\Services\Crm\CrmSegmentService;
use App\Support\CrmConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * P6-A2 operator command. Without `--execute` nothing is created: it only reports what
 * a rebuild would do. Mutating requires BOTH `--execute` AND the rebuild feature flag.
 *
 * Starting a generation always leaves a DURABLE row: if queue processing is disabled,
 * the generation simply waits in `ready` and no work is lost.
 */
final class RebuildCrmSegment extends Command
{
    protected $signature = 'crm:rebuild-segment
        {segmentId : The CRM segment to rebuild}
        {--execute : Actually start the generation (default is a read-only preview)}
        {--batch-size=100 : Contacts scanned per batch, 1..100}';

    protected $description = 'Start a durable CRM segment generation rebuild (preview by default)';

    public function handle(CrmSegmentService $segments): int
    {
        try {
            $segmentId = $this->positiveArgument('segmentId');
            $batchSize = $this->boundedOption('batch-size');
        } catch (Throwable) {
            $this->line('rebuild=invalid_options');

            return self::FAILURE;
        }

        $this->line('segment_id='.$segmentId);
        $this->line('rebuild_processing_enabled='.($this->processingEnabled() ? '1' : '0'));

        if (! $this->option('execute')) {
            $this->line('mode=preview');
            $this->line('generations_started=0');

            return self::SUCCESS;
        }

        try {
            CrmConfig::assertSegmentRebuildEnabled();
        } catch (Throwable) {
            $this->line('mode=disabled');
            $this->line('generations_started=0');

            return self::SUCCESS;
        }

        try {
            $generation = $segments->startGeneration($segmentId, $batchSize);
            $this->line('mode=execute');
            $this->line('generation_id='.$generation['generation_id']);
            $this->line('contact_id_high_water_mark='.$generation['contact_id_high_water_mark']);

            // The generation is durable either way; dispatching only speeds it up.
            if ($this->processingEnabled()) {
                ProcessCrmSegmentGeneration::dispatch($generation['generation_id']);
                $this->line('dispatched=1');
            } else {
                $this->line('dispatched=0');
            }

            $this->line('generations_started=1');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->line('generations_started=0');
            $this->line('status=unavailable');

            return self::FAILURE;
        }
    }

    private function processingEnabled(): bool
    {
        try {
            return CrmConfig::segmentRebuildProcessingEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    private function positiveArgument(string $name): int
    {
        $value = $this->argument($name);

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new \RuntimeException('invalid argument');
        }

        return (int) $value;
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
