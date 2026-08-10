<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CartReminderConfig;
use SensitiveParameter;
use Throwable;

/**
 * P6-C ledger client (D-056 §2, §12).
 *
 * Every read and write goes through the `000028` authorities: the runtime holds neither
 * SELECT nor DML on `cart_reminder_attempts`, so this class is physically the only way
 * in. It performs no mail I/O — the job owns that, deliberately outside any transaction.
 *
 * The ledger carries NO PII. Nothing here ever accepts or returns an address, a name, a
 * cart content or a provider message.
 */
final class CartReminderService
{
    use UsesCrmAuthority;

    /**
     * Abandoned carts eligible for a given step. Identity is filtered by the authority
     * itself — a real `user_id`, active, not soft-deleted, with a verified e-mail — so a
     * guest cart never even reaches the application layer.
     *
     * @return list<array{cart_id:int,user_id:int}>
     */
    public function candidates(?int $afterCartId, int $step): array
    {
        return $this->guarded(function () use ($afterCartId, $step): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_cart_reminder_candidates(?::bigint, ?::integer, ?::integer)',
                [$afterCartId, $step, CartReminderConfig::batchSize()],
            );

            return array_map(static fn (object $r): array => [
                'cart_id' => (int) $r->cart_id,
                'user_id' => (int) $r->user_id,
            ], $rows);
        });
    }

    /**
     * Create the attempt for `(cart, step)`, or return the existing one.
     *
     * Idempotence is STRUCTURAL: the unique index on `(cart_id, step)` is the
     * idempotency key, so a concurrent double enqueue yields one attempt, not two.
     *
     * @return array{attempt_id:int,status:string,created:bool}
     */
    public function enqueue(int $cartId, int $step): array
    {
        return $this->guarded(function () use ($cartId, $step): array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.enqueue_cart_reminder(?::bigint, ?::smallint)',
                [$cartId, $step],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'attempt_id' => (int) $row->attempt_id,
                'status' => (string) $row->status,
                'created' => (bool) $row->created,
            ];
        });
    }

    /** @return list<int> */
    public function dueAttemptIds(): array
    {
        return $this->guarded(function (): array {
            $rows = $this->crmConnection()->select(
                'SELECT attempt_id FROM public.list_due_cart_reminders(?::integer)',
                [CartReminderConfig::batchSize()],
            );

            return array_map(static fn (object $r): int => (int) $r->attempt_id, $rows);
        });
    }

    /**
     * Atomic `pending → claimed`. Two workers cannot both win, so a reminder is never
     * sent twice for the same attempt identity.
     *
     * @return array{attempt_id:int,status:string,cart_id:?int,step:?int}
     */
    public function claim(int $attemptId): array
    {
        return $this->guarded(function () use ($attemptId): array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.claim_cart_reminder(?::bigint)',
                [$attemptId],
            );

            if ($row === null) {
                throw CrmOperationException::unavailable();
            }

            return [
                'attempt_id' => (int) $row->attempt_id,
                'status' => (string) $row->status,
                'cart_id' => $row->cart_id === null ? null : (int) $row->cart_id,
                'step' => $row->step === null ? null : (int) $row->step,
            ];
        });
    }

    /**
     * Store ONLY the digest of a capability that exists in memory.
     *
     * A retry overwrites the previous digest, which is what makes a lost secret
     * permanently unusable rather than merely forgotten.
     */
    public function attachSecret(int $attemptId, #[SensitiveParameter] string $secretHash): string
    {
        return $this->guarded(function () use ($attemptId, $secretHash): string {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.attach_cart_reminder_secret(?::bigint, ?::varchar)',
                [$attemptId, $secretHash],
            );

            return $row === null ? 'unavailable' : (string) $row->status;
        });
    }

    public function complete(int $attemptId): string
    {
        return $this->guarded(function () use ($attemptId): string {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.complete_cart_reminder(?::bigint)',
                [$attemptId],
            );

            return $row === null ? 'unavailable' : (string) $row->status;
        });
    }

    /**
     * A normal, expected outcome — consent withdrawn, cart converted, order covering the
     * cart. The reason is allowlisted by the authority, and the capability digest is
     * destroyed so a suppressed attempt leaves nothing usable behind.
     */
    public function suppress(int $attemptId, string $reason): string
    {
        return $this->guarded(function () use ($attemptId, $reason): string {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.suppress_cart_reminder(?::bigint, ?::varchar)',
                [$attemptId, $reason],
            );

            return $row === null ? 'unavailable' : (string) $row->status;
        });
    }

    /** The code is a SQLSTATE, never a provider or driver message. */
    public function fail(int $attemptId, ?string $sqlState): string
    {
        return $this->guarded(function () use ($attemptId, $sqlState): string {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.fail_cart_reminder(?::bigint, ?::varchar)',
                [$attemptId, $sqlState],
            );

            return $row === null ? 'unavailable' : (string) $row->status;
        });
    }

    /**
     * Resolve a presented capability. Returns null for anything unknown, unsent, or
     * whose cart is no longer abandoned, so the caller cannot tell those cases apart.
     *
     * @return array{attempt_id:int,cart_id:int,cart_public_id:string}|null
     */
    public function resolveBySecret(#[SensitiveParameter] string $secretHash): ?array
    {
        return $this->guarded(function () use ($secretHash): ?array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.resolve_cart_reminder_by_secret(?::varchar)',
                [$secretHash],
            );

            if ($row === null) {
                return null;
            }

            return [
                'attempt_id' => (int) $row->attempt_id,
                'cart_id' => (int) $row->cart_id,
                'cart_public_id' => (string) $row->cart_public_id,
            ];
        });
    }

    /**
     * Remove terminal attempts past the retention window. A pending or claimed attempt
     * is never destroyed: deleting it would silently reset the idempotency key and let
     * the same reminder be sent again.
     *
     * @return list<int>
     */
    public function purge(): array
    {
        CartReminderConfig::assertPurgeEnabled();

        $retention = CartReminderConfig::retentionDays();
        $limit = CartReminderConfig::batchSize();

        return $this->guarded(function () use ($retention, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT attempt_id FROM public.purge_cart_reminders(?::integer, ?::integer)',
                [$retention, $limit],
            );

            return array_map(static fn (object $r): int => (int) $r->attempt_id, $rows);
        });
    }

    /**
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     */
    private function guarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (CrmOperationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never surface a raw database message: it can carry a digest or a cart id.
            throw CrmOperationException::unavailable();
        }
    }
}
