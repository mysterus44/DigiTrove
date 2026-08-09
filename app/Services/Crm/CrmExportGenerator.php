<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Support\CrmConfig;
use App\Support\CrmExportCsvWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * P6-B1 file generator.
 *
 * THE SHAPE IS DELIBERATE (D-036): short DB transaction to claim → COMMIT → paged reads
 * and file I/O with NO transaction open → short DB transaction to finalise. Holding a
 * PostgreSQL transaction open across disk writes would pin a snapshot and a connection
 * for the whole duration of an export, so the guard below refuses to run inside one
 * rather than trusting the caller.
 *
 * The row limit is enforced by reading `row_limit + 1`: if the extra row exists the
 * export FAILS with `row_limit_exceeded` and no file is published. A truncated file
 * presented as complete would silently falsify any decision made from it.
 */
final class CrmExportGenerator
{
    public function __construct(private readonly CrmExportService $exports) {}

    /**
     * Generate one export end to end. Returns the terminal status.
     */
    public function generate(int $exportId): string
    {
        $this->assertOutsideTransaction();

        $claim = $this->exports->claim($exportId);

        if ($claim['status'] !== 'running') {
            // Already claimed, finished or expired: never produce a second file.
            return $claim['status'];
        }

        $kind = (string) $claim['kind'];
        $rowLimit = (int) $claim['row_limit'];
        $generationId = $claim['generation_id'];

        $disk = CrmConfig::exportDisk();
        // Server-generated path. It carries a random token and the export id only — no
        // e-mail, no segment name, no user identifier.
        $path = 'crm-exports/'.$exportId.'-'.Str::random(32).'.csv';

        try {
            [$rowCount, $overflowed] = $this->writeFile($disk, $path, $kind, $generationId, $rowLimit);
        } catch (Throwable) {
            $this->discard($disk, $path);
            $this->exports->fail($exportId, null, 'storage_unavailable');

            return 'failed';
        }

        if ($overflowed) {
            // No partial artefact survives an overflow.
            $this->discard($disk, $path);
            $this->exports->fail($exportId, null, 'row_limit_exceeded');

            return 'failed';
        }

        try {
            $size = (int) Storage::disk($disk)->size($path);
            $checksum = $this->checksum($disk, $path);
        } catch (Throwable) {
            $this->discard($disk, $path);
            $this->exports->fail($exportId, null, 'integrity_failure');

            return 'failed';
        }

        return $this->exports->complete($exportId, $rowCount, $disk, $path, $size, $checksum);
    }

    /**
     * Write the CSV, reading one bounded page at a time.
     *
     * @return array{0:int,1:bool} row count and whether the limit was exceeded
     */
    private function writeFile(string $disk, string $path, string $kind, ?int $generationId, int $rowLimit): array
    {
        $this->assertOutsideTransaction();

        $handle = fopen('php://temp/maxmemory:1048576', 'w+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to open an export buffer.');
        }

        try {
            CrmExportCsvWriter::writeRow($handle, CrmExportCsvWriter::header($kind));

            $rowCount = 0;
            $after = null;

            while (true) {
                $rows = $this->readPage($kind, $generationId, $after);

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    // Read one MORE than the limit: seeing row_limit + 1 is the proof of
                    // overflow, and it is detected before that row is written.
                    if ($rowCount >= $rowLimit) {
                        return [$rowCount, true];
                    }

                    CrmExportCsvWriter::writeRow($handle, [
                        $row['contact_id'],
                        $row['public_id'],
                        $row['email'],
                        $row['status'],
                        $row['origin'],
                        $row['created_at'],
                    ]);

                    $rowCount++;
                    $after = $row['contact_id'];
                }

                if (count($rows) < CrmExportService::READ_CHUNK) {
                    break;
                }
            }

            rewind($handle);
            Storage::disk($disk)->writeStream($path, $handle);

            return [$rowCount, false];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function readPage(string $kind, ?int $generationId, ?int $after): array
    {
        return $kind === 'segment_current_members'
            // The FROZEN generation id, never crm_segments.current_generation_id.
            ? $this->exports->memberRows((int) $generationId, $after)
            : $this->exports->contactRows($after);
    }

    private function checksum(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to read the export artefact.');
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /** Remove a partial artefact. A failure to delete must not mask the real outcome. */
    private function discard(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Intentionally ignored: the row is already terminal and the file is
            // unreachable without a completed row pointing at it.
        }
    }

    private function assertOutsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('CRM export I/O must run outside a database transaction.');
        }
    }
}
