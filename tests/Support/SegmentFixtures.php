<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P6-A2 fixtures. Contacts and commerce rollups are written directly through the
 * owner connection; the rollup rows mirror exactly what the P6-A1.1 authority would
 * produce, so segment matching is exercised against realistic currency-scoped facts.
 */
final class SegmentFixtures
{
    public static function owner(): Connection
    {
        return DB::connection('pgsql_migration');
    }

    public static function contact(string $status = 'active', string $origin = 'guest_order'): int
    {
        // crm_contacts_state_consistency_check: active => email NOT NULL, anonymized => email NULL.
        $email = $status === 'active' ? "'seg_".strtolower(Str::random(10))."@example.com'" : 'NULL';
        $anonymizedAt = $status === 'anonymized' ? 'NOW()' : 'NULL';

        self::owner()->insert(
            "INSERT INTO crm_contacts (public_id, email, user_id, origin, status, anonymized_at, created_at, updated_at) VALUES (?, {$email}, NULL, ?, ?, {$anonymizedAt}, NOW(), NOW())",
            [Str::uuid(), $origin, $status],
        );

        return (int) self::owner()->getPdo()->lastInsertId();
    }

    /**
     * A contact whose created_at is NULL. It must be INSERTed that way: the P6-A0
     * integrity trigger forbids mutating an existing contact's identity columns.
     */
    public static function contactWithoutCreatedAt(): int
    {
        self::owner()->insert(
            "INSERT INTO crm_contacts (public_id, email, user_id, origin, status, anonymized_at, created_at, updated_at) VALUES (?, ?, NULL, 'guest_order', 'active', NULL, NULL, NOW())",
            [Str::uuid(), 'segnull_'.strtolower(Str::random(10)).'@example.com'],
        );

        return (int) self::owner()->getPdo()->lastInsertId();
    }

    /** Record a marketing consent grant using the real P6-A0 column set. */
    public static function grantMarketingConsent(int $contactId): void
    {
        // Real P6-A0 contract: action = 'granted', and an 'account_settings' source
        // carries no order_id (a 'checkout' source would require one).
        self::owner()->insert(
            "INSERT INTO crm_marketing_consent_events (public_id, contact_id, channel, purpose, action, source, policy_version, user_id, order_id, idempotency_hash, recorded_at) VALUES (?, ?, 'email', 'promotional', 'granted', 'account_settings', '2026-01-01', NULL, NULL, ?, NOW())",
            [Str::uuid(), $contactId, hash('sha256', 'consent'.$contactId.Str::random(8))],
        );
    }

    /** Insert a commerce rollup row exactly as the P6-A1.1 authority would shape it. */
    public static function rollup(
        int $contactId,
        string $currency,
        int $acquiredOrders = 1,
        int $gross = 0,
        int $refunded = 0,
        ?string $firstAcquiredAt = null,
        ?string $lastAcquiredAt = null,
    ): void {
        $first = $firstAcquiredAt ?? '2026-01-01T00:00:00Z';
        $last = $lastAcquiredAt ?? $first;

        self::owner()->insert(
            'INSERT INTO crm_contact_commerce_rollups (contact_id, currency, acquired_orders_count, gross_revenue_minor, refunded_amount_minor, first_acquired_at, last_acquired_at, last_refunded_at, calculation_version, refreshed_at) VALUES (?, ?, ?, ?, ?, ?::timestamptz, ?::timestamptz, NULL, 1, NOW())',
            [$contactId, $currency, $acquiredOrders, $gross, $refunded, $first, $last],
        );
    }

    /** @param array<string, mixed> $definition */
    public static function createSegmentWithVersion(array $definition, string $name = 'Segment'): array
    {
        $segmentId = (int) self::owner()->selectOne(
            'SELECT * FROM create_crm_segment(?)',
            [$name.' '.Str::random(6)],
        )->segment_id;

        $versionId = (int) self::owner()->selectOne(
            'SELECT * FROM create_crm_segment_version(?, ?::jsonb)',
            [$segmentId, json_encode($definition, JSON_THROW_ON_ERROR)],
        )->version_id;

        self::owner()->selectOne('SELECT * FROM publish_crm_segment_version(?)', [$versionId]);

        return ['segment_id' => $segmentId, 'version_id' => $versionId];
    }

    /** Run a whole generation to completion and return the published generation id. */
    public static function buildGeneration(int $segmentId, int $batchSize = 100): int
    {
        $generationId = (int) self::owner()->selectOne(
            'SELECT * FROM start_crm_segment_generation(?, ?)',
            [$segmentId, $batchSize],
        )->generation_id;

        for ($i = 0; $i < 200; $i++) {
            $result = self::owner()->selectOne(
                'SELECT * FROM process_crm_segment_generation_batch(?)',
                [$generationId],
            );

            if ($result->status !== 'ready') {
                break;
            }
        }

        return $generationId;
    }

    /** @return list<int> */
    public static function currentMembers(int $segmentId, int $limit = 100): array
    {
        $rows = self::owner()->select(
            'SELECT contact_id FROM list_crm_segment_current_members(?, NULL, ?)',
            [$segmentId, $limit],
        );

        return array_map(static fn (object $r): int => (int) $r->contact_id, $rows);
    }

    /** @param array<string, mixed> $definition */
    public static function matches(int $contactId, array $definition): bool
    {
        return (bool) self::owner()->selectOne(
            'SELECT crm_segment_contact_matches_v1(?, ?::jsonb) AS matched',
            [$contactId, json_encode($definition, JSON_THROW_ON_ERROR)],
        )->matched;
    }

    /** @param array<string, mixed> $criterion */
    public static function definition(array $criterion, string $match = 'all'): array
    {
        return ['schema_version' => 1, 'match' => $match, 'criteria' => [$criterion]];
    }
}
