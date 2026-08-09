<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmConfig;
use Throwable;

/**
 * P6-B1 export authority client. It calls the `000027` authorities and NOTHING else:
 * no Eloquent model, no query builder, no raw statement against `crm_exports`. The
 * runtime holds no table privilege, so this class is physically the only way in.
 *
 * It performs no file I/O: the generator owns that, deliberately OUTSIDE any database
 * transaction (D-036).
 */
final class CrmExportService
{
    use UsesCrmAuthority;

    public const PAGE_SIZE = 25;

    /** Rows fetched per authority call while writing a file. */
    public const READ_CHUNK = 100;

    /**
     * Request an export. The generation of a member export is FROZEN here by the
     * authority; nothing downstream re-reads `current_generation_id`.
     *
     * @return array{export_id:int,public_id:string,status:string,generation_id:?int}
     */
    public function create(string $kind, int $requestedByUserId, ?int $segmentId): array
    {
        return $this->call(function () use ($kind, $requestedByUserId, $segmentId): array {
            // Bounds are validated BEFORE the call, so an invalid flag never creates a row.
            $rowLimit = CrmConfig::exportMaxRows();
            $ttlHours = CrmConfig::exportTtlHours();

            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.create_crm_export(?::varchar, ?::bigint, ?::bigint, ?::integer, ?::integer)',
                [$kind, $requestedByUserId, $segmentId, $rowLimit, $ttlHours],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'export_id' => (int) $row->export_id,
                'public_id' => (string) $row->public_id,
                'status' => (string) $row->status,
                'generation_id' => $row->generation_id === null ? null : (int) $row->generation_id,
            ];
        });
    }

    /**
     * Atomic queued → running claim. Two concurrent workers cannot both win.
     *
     * @return array{export_id:int,status:string,kind:?string,generation_id:?int,row_limit:?int}
     */
    public function claim(int $exportId): array
    {
        return $this->call(function () use ($exportId): array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.claim_crm_export(?::bigint)',
                [$exportId],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'export_id' => (int) $row->export_id,
                'status' => (string) $row->status,
                'kind' => $row->kind === null ? null : (string) $row->kind,
                'generation_id' => $row->generation_id === null ? null : (int) $row->generation_id,
                'row_limit' => $row->row_limit === null ? null : (int) $row->row_limit,
            ];
        });
    }

    public function complete(int $exportId, int $rowCount, string $disk, string $path, int $sizeBytes, string $checksum): string
    {
        return $this->call(function () use ($exportId, $rowCount, $disk, $path, $sizeBytes, $checksum): string {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.complete_crm_export(?::bigint, ?::bigint, ?::varchar, ?::varchar, ?::bigint, ?::varchar)',
                [$exportId, $rowCount, $disk, $path, $sizeBytes, $checksum],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return (string) $row->status;
        });
    }

    /** The reason is an allowlisted token and the code a SQLSTATE — never a message. */
    public function fail(int $exportId, ?string $sqlState, string $terminalReason): string
    {
        return $this->call(function () use ($exportId, $sqlState, $terminalReason): string {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.fail_crm_export(?::bigint, ?::varchar, ?::varchar)',
                [$exportId, $sqlState, $terminalReason],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return (string) $row->status;
        });
    }

    /** @return array<string, mixed>|null */
    public function get(int $exportId): ?array
    {
        return $this->call(function () use ($exportId): ?array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.get_crm_export(?::bigint)',
                [$exportId],
            );

            return $row === null ? null : $this->mapExport($row);
        });
    }

    /** @return list<array<string, mixed>> Newest first. */
    public function list(?int $beforeExportId = null, int $limit = self::PAGE_SIZE): array
    {
        return $this->call(function () use ($beforeExportId, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_crm_exports(?::bigint, ?::integer)',
                [$beforeExportId, $limit],
            );

            return array_map(static fn (object $r): array => [
                'export_id' => (int) $r->export_id,
                'public_id' => (string) $r->public_id,
                'requested_by_user_id' => (int) $r->requested_by_user_id,
                'kind' => (string) $r->kind,
                'status' => (string) $r->status,
                'segment_id' => $r->segment_id === null ? null : (int) $r->segment_id,
                'row_count' => $r->row_count === null ? null : (int) $r->row_count,
                'expires_at' => $r->expires_at,
                'created_at' => $r->created_at,
                'completed_at' => $r->completed_at,
            ], $rows);
        });
    }

    /** @return list<int> */
    public function dueExportIds(int $limit): array
    {
        return $this->call(function () use ($limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT export_id FROM public.list_due_crm_exports(?::integer)',
                [$limit],
            );

            return array_map(static fn (object $r): int => (int) $r->export_id, $rows);
        });
    }

    /**
     * Mark expired exports and return what to delete from disk. Bounded and idempotent:
     * a second run expires nothing new.
     *
     * @return list<array{export_id:int,storage_disk:?string,storage_path:?string}>
     */
    public function expire(int $limit): array
    {
        return $this->call(function () use ($limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.expire_crm_exports(?::integer)',
                [$limit],
            );

            return array_map(static fn (object $r): array => [
                'export_id' => (int) $r->export_id,
                'storage_disk' => $r->storage_disk === null ? null : (string) $r->storage_disk,
                'storage_path' => $r->storage_path === null ? null : (string) $r->storage_path,
            ], $rows);
        });
    }

    /** @return list<array<string, mixed>> */
    public function contactRows(?int $afterContactId, int $limit = self::READ_CHUNK): array
    {
        return $this->call(function () use ($afterContactId, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_crm_export_contact_rows(?::bigint, ?::integer)',
                [$afterContactId, $limit],
            );

            return array_map($this->mapContactRow(...), $rows);
        });
    }

    /**
     * Rows of ONE frozen generation. The caller passes the id captured at creation, so a
     * concurrent rebuild cannot leak a second generation into the same file.
     *
     * @return list<array<string, mixed>>
     */
    public function memberRows(int $generationId, ?int $afterContactId, int $limit = self::READ_CHUNK): array
    {
        return $this->call(function () use ($generationId, $afterContactId, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_crm_export_member_rows(?::bigint, ?::bigint, ?::integer)',
                [$generationId, $afterContactId, $limit],
            );

            return array_map($this->mapContactRow(...), $rows);
        });
    }

    /** @return array<string, mixed> */
    private function mapContactRow(object $row): array
    {
        return [
            'contact_id' => (int) $row->contact_id,
            'public_id' => (string) $row->public_id,
            // An anonymized contact has no address at all (P6-A0 state CHECK).
            'email' => $row->email === null ? null : (string) $row->email,
            'status' => (string) $row->status,
            'origin' => (string) $row->origin,
            'created_at' => (string) $row->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function mapExport(object $row): array
    {
        return [
            'export_id' => (int) $row->export_id,
            'public_id' => (string) $row->public_id,
            'requested_by_user_id' => (int) $row->requested_by_user_id,
            'kind' => (string) $row->kind,
            'status' => (string) $row->status,
            'segment_id' => $row->segment_id === null ? null : (int) $row->segment_id,
            'generation_id' => $row->generation_id === null ? null : (int) $row->generation_id,
            'row_limit' => (int) $row->row_limit,
            'row_count' => $row->row_count === null ? null : (int) $row->row_count,
            'storage_disk' => $row->storage_disk === null ? null : (string) $row->storage_disk,
            'storage_path' => $row->storage_path === null ? null : (string) $row->storage_path,
            'size_bytes' => $row->size_bytes === null ? null : (int) $row->size_bytes,
            'checksum_sha256' => $row->checksum_sha256 === null ? null : (string) $row->checksum_sha256,
            'last_error_code' => $row->last_error_code === null ? null : (string) $row->last_error_code,
            'terminal_reason' => $row->terminal_reason === null ? null : (string) $row->terminal_reason,
            'expires_at' => $row->expires_at,
            'created_at' => $row->created_at,
            'completed_at' => $row->completed_at,
        ];
    }

    /**
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     */
    private function call(callable $operation): mixed
    {
        try {
            CrmConfig::assertExportsEnabled();

            return $operation();
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never surface a raw database message to an admin screen.
            throw CrmOperationException::unavailable();
        }
    }
}
