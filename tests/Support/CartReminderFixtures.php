<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * P6-C fixtures.
 *
 * Carts are built with direct SQL on purpose: the application has NO cart creation or
 * mutation flow (no route, no controller, no service), which is exactly the precondition
 * D-056 records. These fixtures stand in for the storefront that does not exist yet.
 */
final class CartReminderFixtures
{
    /** Create a cart whose last activity is `$inactiveMinutes` in the past. */
    public static function cart(
        string $status = 'active',
        int $inactiveMinutes = 0,
        ?int $userId = null,
    ): int {
        SegmentFixtures::owner()->insert(
            <<<'SQL'
            INSERT INTO carts (public_id, secret_hash, user_id, currency, status, expires_at,
                               last_activity_at, abandoned_at, created_at, updated_at)
            VALUES (gen_random_uuid(), md5(random()::text) || md5(random()::text), ?, 'XOF', ?,
                    now() + interval '30 days',
                    now() - make_interval(mins => ?),
                    CASE WHEN ? = 'abandoned' THEN now() ELSE NULL END,
                    now(), now())
            SQL,
            [$userId, $status, $inactiveMinutes, $status],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM carts ORDER BY id DESC LIMIT 1')->id;
    }

    /** An active, non-deleted, e-mail-verified customer: the only V1-addressable identity. */
    public static function eligibleUser(?string $email = null): User
    {
        return User::factory()->create([
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ] + ($email === null ? [] : ['email' => $email]));
    }

    public static function addItem(int $cartId, int $productId, int $quantity = 1): int
    {
        SegmentFixtures::owner()->insert(
            'INSERT INTO cart_items (cart_id, product_id, quantity, created_at, updated_at) VALUES (?, ?, ?, now(), now())',
            [$cartId, $productId, $quantity],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM cart_items ORDER BY id DESC LIMIT 1')->id;
    }

    /**
     * An ACTIVE contact carrying an exact address. `SegmentFixtures::contact()` mints a
     * random one, but P6-C resolves the contact from the user's own e-mail, so the two
     * must match exactly — the resolution is exact-match only, by design.
     */
    public static function contactFor(string $email): int
    {
        SegmentFixtures::owner()->insert(
            "INSERT INTO crm_contacts (public_id, email, user_id, origin, status, anonymized_at, created_at, updated_at)
             VALUES (?, ?, NULL, 'verified_account', 'active', NULL, NOW(), NOW())",
            [Str::uuid(), mb_strtolower(trim($email))],
        );

        return (int) SegmentFixtures::owner()->getPdo()->lastInsertId();
    }

    /** Append a withdrawal to the consent ledger, exactly as P6-A0 shapes it. */
    public static function withdrawConsent(int $contactId): void
    {
        SegmentFixtures::owner()->insert(
            "INSERT INTO crm_marketing_consent_events (public_id, contact_id, channel, purpose, action, source, policy_version, user_id, order_id, idempotency_hash, recorded_at)
             VALUES (?, ?, 'email', 'promotional', 'withdrawn', 'account_settings', '2026-01-01', NULL, NULL, ?, NOW())",
            [Str::uuid(), $contactId, hash('sha256', 'withdraw'.$contactId.Str::random(8))],
        );
    }

    public static function row(int $cartId): object
    {
        return SegmentFixtures::owner()->selectOne('SELECT * FROM carts WHERE id = ?', [$cartId]);
    }

    public static function attempt(int $attemptId): ?object
    {
        return SegmentFixtures::owner()->selectOne('SELECT * FROM cart_reminder_attempts WHERE id = ?', [$attemptId]);
    }

    public static function abandonedCount(): int
    {
        return (int) SegmentFixtures::owner()->selectOne("SELECT count(*) AS c FROM carts WHERE status = 'abandoned'")->c;
    }

    /** Push a cart's activity clock into the past without going through the trigger. */
    public static function ageActivity(int $cartId, int $minutes): void
    {
        SegmentFixtures::owner()->update(
            'UPDATE carts SET last_activity_at = now() - make_interval(mins => ?) WHERE id = ?',
            [$minutes, $cartId],
        );
    }
}
