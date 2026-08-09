<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Crm\CrmExportService;
use App\Support\CrmConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * P6-B1 purge. Bounded and idempotent: the authority marks a bounded page of expired
 * exports and returns what to delete, so a second run finds nothing new.
 *
 * The database transition happens FIRST and the file deletion afterwards, outside it.
 * If deletion fails the row is still expired and therefore already undownloadable — the
 * artefact is unreachable even while it lingers on disk, and the next run retries.
 */
final class PurgeExpiredCrmExports extends Command
{
    protected $signature = 'crm:purge-expired-exports {--limit=100 : Exports to expire per run, 1..100}';

    protected $description = 'Expire timed-out CRM exports and delete their artefacts';

    public function handle(CrmExportService $exports): int
    {
        try {
            CrmConfig::assertExportPurgeEnabled();
        } catch (Throwable) {
            $this->line('mode=disabled');
            $this->line('expired=0');

            return self::SUCCESS;
        }

        try {
            $limit = $this->boundedLimit();
        } catch (Throwable) {
            $this->line('purge=invalid_options');

            return self::FAILURE;
        }

        try {
            $expired = $exports->expire($limit);
        } catch (Throwable) {
            $this->line('expired=0');
            $this->line('status=unavailable');

            return self::FAILURE;
        }

        $deleted = 0;

        foreach ($expired as $export) {
            if ($export['storage_disk'] === null || $export['storage_path'] === null) {
                continue;
            }

            try {
                Storage::disk($export['storage_disk'])->delete($export['storage_path']);
                $deleted++;
            } catch (Throwable) {
                // The row is already expired, so the artefact is undownloadable either
                // way. The next run retries the deletion.
            }
        }

        $this->line('mode=execute');
        $this->line('expired='.count($expired));
        $this->line('artefacts_deleted='.$deleted);

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
