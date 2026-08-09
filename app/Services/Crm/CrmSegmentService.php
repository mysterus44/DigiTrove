<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmConfig;
use Throwable;

/**
 * Thin client over the P6-A2 segment authorities. It never builds SQL from a
 * definition, never evaluates criteria in PHP and never reads the segment tables
 * directly: the PostgreSQL authorities validate, materialise and publish.
 */
final class CrmSegmentService
{
    use UsesCrmAuthority;

    /** @return array{segment_id:int,name:string,status:string} */
    public function createSegment(string $name): array
    {
        return $this->call(function () use ($name): array {
            $row = $this->crmConnection()->selectOne('SELECT * FROM public.create_crm_segment(?::varchar)', [$name]);

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'segment_id' => (int) $row->segment_id,
                'name' => (string) $row->name,
                'status' => (string) $row->status,
            ];
        });
    }

    /**
     * The definition travels as JSON and is validated INSIDE PostgreSQL against the
     * closed allowlist. PHP performs no interpretation of its contents.
     *
     * @param  array<string, mixed>  $definition
     * @return array{version_id:int,segment_id:int,version_number:int,status:string}
     */
    public function createVersion(int $segmentId, array $definition): array
    {
        return $this->call(function () use ($segmentId, $definition): array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.create_crm_segment_version(?::bigint, ?::jsonb)',
                [$segmentId, json_encode($definition, JSON_THROW_ON_ERROR)],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'version_id' => (int) $row->version_id,
                'segment_id' => (int) $row->segment_id,
                'version_number' => (int) $row->version_number,
                'status' => (string) $row->status,
            ];
        });
    }

    public function publishVersion(int $versionId): string
    {
        return $this->call(function () use ($versionId): string {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.publish_crm_segment_version(?::bigint)',
                [$versionId],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return (string) $row->status;
        });
    }

    /** @return array{generation_id:int,segment_id:int,segment_version_id:int,contact_id_high_water_mark:int,status:string} */
    public function startGeneration(int $segmentId, int $batchSize): array
    {
        return $this->call(function () use ($segmentId, $batchSize): array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.start_crm_segment_generation(?::bigint, ?::integer)',
                [$segmentId, $batchSize],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'generation_id' => (int) $row->generation_id,
                'segment_id' => (int) $row->segment_id,
                'segment_version_id' => (int) $row->segment_version_id,
                'contact_id_high_water_mark' => (int) $row->contact_id_high_water_mark,
                'status' => (string) $row->status,
            ];
        });
    }

    /** @return array{generation_id:int,status:string,scanned_in_batch:int,matched_in_batch:int,members_count:?int} */
    public function processBatch(int $generationId): array
    {
        return $this->call(function () use ($generationId): array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.process_crm_segment_generation_batch(?::bigint)',
                [$generationId],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'generation_id' => (int) $row->generation_id,
                'status' => (string) $row->status,
                'scanned_in_batch' => (int) $row->scanned_in_batch,
                'matched_in_batch' => (int) $row->matched_in_batch,
                'members_count' => $row->members_count === null ? null : (int) $row->members_count,
            ];
        });
    }

    public function retryGeneration(int $generationId): string
    {
        return $this->call(function () use ($generationId): string {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.retry_crm_segment_generation(?::bigint)',
                [$generationId],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return (string) $row->status;
        });
    }

    /** @return array<string, int|string|null>|null */
    public function generation(int $generationId): ?array
    {
        return $this->call(function () use ($generationId): ?array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.get_crm_segment_generation(?::bigint)',
                [$generationId],
            );

            if ($row === null) {
                return null;
            }

            return [
                'generation_id' => (int) $row->generation_id,
                'segment_id' => (int) $row->segment_id,
                'segment_version_id' => (int) $row->segment_version_id,
                'contact_id_high_water_mark' => (int) $row->contact_id_high_water_mark,
                'batch_size' => (int) $row->batch_size,
                'cursor_contact_id' => $row->cursor_contact_id === null ? null : (int) $row->cursor_contact_id,
                'status' => (string) $row->status,
                'members_count' => (int) $row->members_count,
                'last_error_code' => $row->last_error_code === null ? null : (string) $row->last_error_code,
            ];
        });
    }

    /**
     * Bounded keyset listing over the P6-A2 authority. No OFFSET, no direct table read.
     *
     * @return list<array<string, mixed>>
     */
    public function listSegments(?int $afterSegmentId, int $limit): array
    {
        return $this->call(function () use ($afterSegmentId, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_crm_segments(?::bigint, ?::integer)',
                [$afterSegmentId, $limit],
            );

            return array_map(static fn (object $r): array => [
                'segment_id' => (int) $r->segment_id,
                'name' => (string) $r->name,
                'status' => (string) $r->status,
                'current_version_number' => $r->current_version_number === null ? null : (int) $r->current_version_number,
                'current_generation_id' => $r->current_generation_id === null ? null : (int) $r->current_generation_id,
                'current_members_count' => $r->current_members_count === null ? null : (int) $r->current_members_count,
                'generation_published_at' => $r->generation_published_at,
            ], $rows);
        });
    }

    /** @return array<string, mixed>|null */
    public function segment(int $segmentId): ?array
    {
        return $this->call(function () use ($segmentId): ?array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.get_crm_segment(?::bigint)',
                [$segmentId],
            );

            if ($row === null) {
                return null;
            }

            return [
                'segment_id' => (int) $row->segment_id,
                'name' => (string) $row->name,
                'status' => (string) $row->status,
                'current_version_id' => $row->current_version_id === null ? null : (int) $row->current_version_id,
                'current_version_number' => $row->current_version_number === null ? null : (int) $row->current_version_number,
                'current_generation_id' => $row->current_generation_id === null ? null : (int) $row->current_generation_id,
                'current_members_count' => $row->current_members_count === null ? null : (int) $row->current_members_count,
                'generation_published_at' => $row->generation_published_at,
            ];
        });
    }

    /** @return list<int> Contact ids of the CURRENT published generation only. */
    public function currentMembers(int $segmentId, ?int $afterContactId, int $limit): array
    {
        return $this->call(function () use ($segmentId, $afterContactId, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT contact_id FROM public.list_crm_segment_current_members(?::bigint, ?::bigint, ?::integer)',
                [$segmentId, $afterContactId, $limit],
            );

            return array_map(static fn (object $row): int => (int) $row->contact_id, $rows);
        });
    }

    /** @return list<int> */
    public function dueGenerationIds(int $limit): array
    {
        return $this->call(function () use ($limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT generation_id FROM public.list_due_crm_segment_generations(?::integer)',
                [$limit],
            );

            return array_map(static fn (object $row): int => (int) $row->generation_id, $rows);
        });
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
            CrmConfig::assertEnabled();

            return $operation();
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }
}
