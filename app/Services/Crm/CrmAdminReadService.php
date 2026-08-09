<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Services\Crm\Concerns\UsesCrmAuthority;
use App\Support\CrmConfig;
use Throwable;

/**
 * P6-B0 admin read layer. It calls the P6-B0.1 / P6-A2 authorities and NOTHING else:
 * no Eloquent model, no DB::table, no raw SELECT on a crm_* table. The runtime holds
 * no table privilege, so this class is physically the only way the admin UI reaches
 * CRM data.
 *
 * It reconstructs no financial value: commerce rows arrive already computed, one per
 * currency, and are never summed across currencies.
 *
 * The e-mail lookup is delegated to the authority, which applies the P6-A0 normaliser
 * (`lower(btrim(...))` over CITEXT) and matches EXACTLY. No PHP-side matching exists,
 * so the UI cannot drift from the database contract.
 */
final class CrmAdminReadService
{
    use UsesCrmAuthority;

    public const PAGE_SIZE = 25;

    /** @return list<array<string, mixed>> */
    public function listContacts(?int $afterContactId, ?string $status, ?string $origin, int $limit = self::PAGE_SIZE): array
    {
        return $this->call(function () use ($afterContactId, $status, $origin, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_crm_contacts(?::bigint, ?::varchar, ?::varchar, ?::integer)',
                [$afterContactId, $status, $origin, $limit],
            );

            return array_map(static fn (object $r): array => [
                'contact_id' => (int) $r->contact_id,
                'public_id' => (string) $r->public_id,
                'email' => $r->email === null ? null : (string) $r->email,
                'status' => (string) $r->status,
                'origin' => (string) $r->origin,
                'created_at' => $r->created_at,
            ], $rows);
        });
    }

    /** @return array<string, mixed>|null */
    public function contact(int $contactId): ?array
    {
        return $this->call(function () use ($contactId): ?array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.get_crm_contact(?::bigint)',
                [$contactId],
            );

            if ($row === null) {
                return null;
            }

            return [
                'contact_id' => (int) $row->contact_id,
                'public_id' => (string) $row->public_id,
                // An anonymized contact has no e-mail at all (P6-A0 state CHECK): there
                // is nothing to mask because there is nothing left to reveal.
                'email' => $row->email === null ? null : (string) $row->email,
                'status' => (string) $row->status,
                'origin' => (string) $row->origin,
                'anonymized_at' => $row->anonymized_at,
                'created_at' => $row->created_at,
            ];
        });
    }

    /**
     * EXACT normalised e-mail only. The needle is never logged, never placed in a URL
     * and never used as a cache key.
     *
     * @return array<string, mixed>|null
     */
    public function findContactByExactEmail(string $email): ?array
    {
        return $this->call(function () use ($email): ?array {
            $row = $this->crmConnection()->selectOne(
                'SELECT * FROM public.find_crm_contact_by_exact_email(?::varchar)',
                [$email],
            );

            if ($row === null) {
                return null;
            }

            return [
                'contact_id' => (int) $row->contact_id,
                'public_id' => (string) $row->public_id,
                'email' => $row->email === null ? null : (string) $row->email,
                'status' => (string) $row->status,
                'origin' => (string) $row->origin,
                'created_at' => $row->created_at,
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    public function consentEvents(int $contactId, ?int $afterEventId = null, int $limit = self::PAGE_SIZE): array
    {
        return $this->call(function () use ($contactId, $afterEventId, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_crm_contact_consent_events(?::bigint, ?::bigint, ?::integer)',
                [$contactId, $afterEventId, $limit],
            );

            return array_map(static fn (object $r): array => [
                'event_id' => (int) $r->event_id,
                'public_id' => (string) $r->public_id,
                'channel' => (string) $r->channel,
                'purpose' => (string) $r->purpose,
                'action' => (string) $r->action,
                'source' => (string) $r->source,
                'policy_version' => (string) $r->policy_version,
                'recorded_at' => $r->recorded_at,
            ], $rows);
        });
    }

    /**
     * One row PER CURRENCY, already computed by the P6-A1.1 authority. This method
     * deliberately offers no total: a cross-currency sum has no meaning here.
     *
     * @return list<array<string, mixed>>
     */
    public function commerceRollups(int $contactId): array
    {
        return $this->call(function () use ($contactId): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_crm_contact_commerce_rollups(?::bigint)',
                [$contactId],
            );

            return array_map(static fn (object $r): array => [
                'currency' => (string) $r->currency,
                'acquired_orders_count' => (int) $r->acquired_orders_count,
                'gross_revenue_minor' => (int) $r->gross_revenue_minor,
                'refunded_amount_minor' => (int) $r->refunded_amount_minor,
                'net_revenue_minor' => (int) $r->net_revenue_minor,
                'first_acquired_at' => $r->first_acquired_at,
                'last_acquired_at' => $r->last_acquired_at,
                'last_refunded_at' => $r->last_refunded_at,
                'refreshed_at' => $r->refreshed_at,
            ], $rows);
        });
    }

    /** @return list<array<string, mixed>> Current published memberships only. */
    public function segmentMemberships(int $contactId, ?int $afterSegmentId = null, int $limit = self::PAGE_SIZE): array
    {
        return $this->call(function () use ($contactId, $afterSegmentId, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_crm_contact_segment_memberships(?::bigint, ?::bigint, ?::integer)',
                [$contactId, $afterSegmentId, $limit],
            );

            return array_map(static fn (object $r): array => [
                'segment_id' => (int) $r->segment_id,
                'name' => (string) $r->name,
                'generation_id' => (int) $r->generation_id,
                'generation_published_at' => $r->generation_published_at,
            ], $rows);
        });
    }

    /** @return list<array<string, mixed>> */
    public function segmentVersions(int $segmentId, ?int $afterVersionNumber = null, int $limit = self::PAGE_SIZE): array
    {
        return $this->call(function () use ($segmentId, $afterVersionNumber, $limit): array {
            $rows = $this->crmConnection()->select(
                'SELECT * FROM public.list_crm_segment_versions(?::bigint, ?::integer, ?::integer)',
                [$segmentId, $afterVersionNumber, $limit],
            );

            return array_map(static fn (object $r): array => [
                'version_id' => (int) $r->version_id,
                'version_number' => (int) $r->version_number,
                'definition_schema_version' => (int) $r->definition_schema_version,
                'definition' => $r->definition === null ? null : (string) $r->definition,
                'status' => (string) $r->status,
                'published_at' => $r->published_at,
                'created_at' => $r->created_at,
            ], $rows);
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
            // Never surface a raw database message to an admin screen.
            throw CrmOperationException::unavailable();
        }
    }
}
