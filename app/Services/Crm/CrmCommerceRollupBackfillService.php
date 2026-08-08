<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmConfig;
use Throwable;

/**
 * Operator client over the P6-A1.3 backfill authorities. It performs no financial
 * computation, resolves no identity and mutates nothing itself: it drives the
 * PostgreSQL authorities, which select the historical pairs from the authoritative
 * source and hand them to the P6-A1.2 enqueue authority.
 *
 * `preview()` is read-only and powers the dry-run. Every mutating call additionally
 * requires the backfill feature flag: the command's `--execute` switch is the second,
 * independent barrier.
 */
final class CrmCommerceRollupBackfillService
{
    use UsesCrmAuthority;

    /**
     * Read-only candidate preview. Never creates a run, never enqueues.
     *
     * @return list<array{contact_id:int,currency:string}>
     */
    public function preview(int $limit, ?int $cursorContactId = null, ?string $cursorCurrency = null): array
    {
        try {
            CrmConfig::assertEnabled();
            $connection = $this->crmConnection();
            $snapshot = $this->currentHighWaterMark();

            $rows = $connection->select(
                'SELECT contact_id, currency FROM public.list_crm_commerce_rollup_backfill_candidates(?::bigint, ?::bigint, ?::varchar, ?::integer)',
                [$snapshot, $cursorContactId, $cursorCurrency, $limit],
            );

            return array_map(
                static fn (object $row): array => [
                    'contact_id' => (int) $row->contact_id,
                    'currency' => (string) $row->currency,
                ],
                $rows,
            );
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }

    /**
     * The frozen boundary a run would capture right now (preview only). Read through
     * the authority: the runtime has no SELECT on crm_order_attributions (P6-A1.0).
     */
    public function currentHighWaterMark(): int
    {
        try {
            CrmConfig::assertEnabled();
            $row = $this->crmConnection()->selectOne(
                'SELECT public.current_crm_commerce_rollup_backfill_high_water_mark() AS snapshot',
            );

            return $row === null ? 0 : (int) $row->snapshot;
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }

    /** @return array{run_id:int,attribution_order_id_high_water_mark:int,batch_size:int,status:string} */
    public function start(int $batchSize): array
    {
        return $this->mutating(function () use ($batchSize): array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.start_crm_commerce_rollup_backfill(?::integer)',
                [$batchSize],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'run_id' => (int) $row->run_id,
                'attribution_order_id_high_water_mark' => (int) $row->attribution_order_id_high_water_mark,
                'batch_size' => (int) $row->batch_size,
                'status' => (string) $row->status,
            ];
        });
    }

    /** @return array{run_id:int,status:string,enqueued_in_batch:int,enqueued_pairs_count:?int,batches_processed_count:?int} */
    public function processBatch(int $runId): array
    {
        return $this->mutating(function () use ($runId): array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.process_crm_commerce_rollup_backfill_batch(?::bigint)',
                [$runId],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'run_id' => (int) $row->run_id,
                'status' => (string) $row->status,
                'enqueued_in_batch' => (int) $row->enqueued_in_batch,
                'enqueued_pairs_count' => $row->enqueued_pairs_count === null ? null : (int) $row->enqueued_pairs_count,
                'batches_processed_count' => $row->batches_processed_count === null ? null : (int) $row->batches_processed_count,
            ];
        });
    }

    public function retry(int $runId): string
    {
        return $this->mutating(function () use ($runId): string {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.retry_crm_commerce_rollup_backfill_run(?::bigint)',
                [$runId],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return (string) $row->status;
        });
    }

    /** @return array<string, int|string|null>|null */
    public function run(int $runId): ?array
    {
        try {
            CrmConfig::assertEnabled();
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.get_crm_commerce_rollup_backfill_run(?::bigint)',
                [$runId],
            );

            if ($row === null) {
                return null;
            }

            return [
                'run_id' => (int) $row->run_id,
                'attribution_order_id_high_water_mark' => (int) $row->attribution_order_id_high_water_mark,
                'batch_size' => (int) $row->batch_size,
                'cursor_contact_id' => $row->cursor_contact_id === null ? null : (int) $row->cursor_contact_id,
                'cursor_currency' => $row->cursor_currency === null ? null : (string) $row->cursor_currency,
                'batches_processed_count' => (int) $row->batches_processed_count,
                'enqueued_pairs_count' => (int) $row->enqueued_pairs_count,
                'status' => (string) $row->status,
                'last_error_code' => $row->last_error_code === null ? null : (string) $row->last_error_code,
            ];
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }

    /**
     * Every mutating authority is gated by the backfill feature flag, on top of the
     * command's explicit --execute switch.
     *
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     */
    private function mutating(callable $operation): mixed
    {
        try {
            CrmConfig::assertCommerceRollupBackfillEnabled();

            return $operation();
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CrmOperationException::unavailable();
        }
    }
}
