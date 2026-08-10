<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P6-D0 fixtures.
 *
 * Rows are built with direct SQL through the owner connection because P6-D0 ships NO
 * application layer: there is no service, no authority and no flow that could create an
 * affiliate, a touch or a commission. These fixtures exercise the schema contract only.
 */
final class AffiliateFixtures
{
    public static function user(): int
    {
        return (int) User::factory()->create([
            'role' => UserRole::Customer,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ])->id;
    }

    public static function affiliate(int $userId, string $status = 'pending'): int
    {
        SegmentFixtures::owner()->insert(
            "INSERT INTO affiliates (public_id, user_id, status, applied_at, approved_at, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, ?, now(), CASE WHEN ? = 'active' THEN now() ELSE NULL END, now(), now())",
            [$userId, $status, $status],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM affiliates ORDER BY id DESC LIMIT 1')->id;
    }

    public static function code(int $affiliateId, string $code): int
    {
        SegmentFixtures::owner()->insert(
            'INSERT INTO affiliate_codes (affiliate_id, code, is_active, created_at, updated_at) VALUES (?, ?, true, now(), now())',
            [$affiliateId, $code],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM affiliate_codes ORDER BY id DESC LIMIT 1')->id;
    }

    public static function touch(int $affiliateId, int $codeId, ?int $userId = null, ?string $visitorId = null): int
    {
        SegmentFixtures::owner()->insert(
            "INSERT INTO affiliate_touches (affiliate_id, affiliate_code_id, visitor_id, user_id, source, occurred_at, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'code', now(), now() + interval '30 days', now(), now())",
            [$affiliateId, $codeId, $visitorId, $userId],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM affiliate_touches ORDER BY id DESC LIMIT 1')->id;
    }

    /** The arbitrated policy: 15 %, 30-day window, 14-day delay, 10 000 XOF threshold. */
    public static function policy(int $version = 1, string $status = 'draft'): int
    {
        SegmentFixtures::owner()->insert(
            "INSERT INTO affiliate_program_policies (public_id, version, status, attribution_model, attribution_window_days, default_commission_bps, payable_delay_days, payout_threshold_minor, payout_currency, manual_payout_only, effective_from, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, ?, 'code_then_last_click', 30, 1500, 14, 10000, 'XOF', true, now(), now(), now())",
            [$version, $status],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM affiliate_program_policies ORDER BY id DESC LIMIT 1')->id;
    }

    /**
     * A paid order with one line, plus an affiliate ready to be credited for it.
     *
     * @return array{order_id:int,order_item_id:int,affiliate_id:int,code_id:int,policy_id:int,payment_id:int}
     */
    public static function attributableOrder(): array
    {
        $affiliateId = self::affiliate(self::user(), 'active');
        $codeId = self::code($affiliateId, 'AFF'.str_pad((string) $affiliateId, 5, '0', STR_PAD_LEFT));
        $policyId = self::policy(version: random_int(1_000, 9_999));

        $buyer = User::factory()->create(['role' => UserRole::Customer, 'status' => UserStatus::Active]);
        $productId = (int) Product::factory()->create()->id;

        // `orders` carries DEFERRED checks — at least one line, and exactly one succeeded
        // payment for a paid order — so the order, its item and its payment must all
        // commit together, and therefore on the SAME connection.
        $order = DB::transaction(function () use ($buyer, $productId): Order {
            $order = Order::factory()->create([
                'status' => OrderStatus::Paid,
                'user_id' => $buyer->id,
                'customer_email' => $buyer->email,
                'subtotal_minor' => 5_000,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 5_000,
                'currency' => 'XOF',
                'paid_at' => now(),
            ]);

            DB::table('order_items')->insert([
                'order_id' => $order->id,
                'product_id' => $productId,
                'purchased_product_id' => $productId,
                'product_name_snapshot' => 'Affiliate fixture',
                'product_slug_snapshot' => 'affiliate-fixture',
                'product_type_snapshot' => 'ebook',
                'unit_price_minor' => 5_000,
                'quantity' => 1,
                // The commission base: line_total_minor = line_subtotal - line_discount.
                'line_subtotal_minor' => 5_000,
                'line_discount_minor' => 0,
                'line_total_minor' => 5_000,
                'currency' => 'XOF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('payments')->insert([
                'order_id' => $order->id,
                'public_id' => (string) Str::uuid(),
                'provider' => 'cinetpay',
                'attempt_number' => 1,
                'amount_minor' => 5_000,
                'currency' => 'XOF',
                'status' => 'succeeded',
                'idempotency_key_hash' => hash('sha256', 'affiliate-fixture-'.$order->id),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $order;
        });

        $orderItemId = (int) SegmentFixtures::owner()->selectOne(
            'SELECT id FROM order_items WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$order->id],
        )->id;

        $paymentId = (int) SegmentFixtures::owner()->selectOne(
            'SELECT id FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$order->id],
        )->id;

        return [
            'order_id' => (int) $order->id,
            'order_item_id' => $orderItemId,
            'affiliate_id' => $affiliateId,
            'code_id' => $codeId,
            'policy_id' => $policyId,
            'payment_id' => $paymentId,
        ];
    }

    public static function attribution(int $orderId, int $affiliateId, int $codeId, int $policyId): int
    {
        SegmentFixtures::owner()->insert(
            "INSERT INTO affiliate_attributions (order_id, affiliate_id, affiliate_code_id, affiliate_touch_id, policy_id, matched_by, attributed_at, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, 'code', now(), now(), now())",
            [$orderId, $affiliateId, $codeId, $policyId],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM affiliate_attributions ORDER BY id DESC LIMIT 1')->id;
    }

    /** @param array<string, int> $ctx */
    public static function commission(
        array $ctx,
        int $attributionId,
        int $amount,
        int $base,
        int $rateBps = 1500,
        string $currency = 'XOF',
        string $baseKind = 'line_total_after_discount',
    ): int {
        SegmentFixtures::owner()->insert(
            "INSERT INTO affiliate_commissions (public_id, affiliate_id, order_id, order_item_id, attribution_id, policy_id, status, rate_bps_snapshot, base_kind_snapshot, base_amount_minor_snapshot, amount_minor, currency, payable_delay_days_snapshot, payable_at, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, 14, now() + interval '14 days', now(), now())",
            [$ctx['affiliate_id'], $ctx['order_id'], $ctx['order_item_id'], $attributionId, $ctx['policy_id'], $rateBps, $baseKind, $base, $amount, $currency],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM affiliate_commissions ORDER BY id DESC LIMIT 1')->id;
    }

    public static function entry(
        int $affiliateId,
        ?int $commissionId,
        string $type,
        int $amount,
        string $currency = 'XOF',
        ?int $refundId = null,
        ?string $reasonCode = null,
    ): int {
        SegmentFixtures::owner()->insert(
            'INSERT INTO affiliate_commission_entries (affiliate_id, commission_id, entry_type, amount_minor, currency, refund_id, reason_code, occurred_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, now(), now())',
            [$affiliateId, $commissionId, $type, $amount, $currency, $refundId, $reasonCode],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM affiliate_commission_entries ORDER BY id DESC LIMIT 1')->id;
    }

    /**
     * A PARTIAL succeeded refund.
     *
     * Commerce enforces that the refunds total matches the order status, so the order is
     * moved to `partially_refunded` in the same transaction — the deferred check fires at
     * commit and would otherwise reject a refund against a still-`paid` order.
     *
     * @param  array<string, int>  $ctx
     */
    public static function refund(array $ctx, int $amount = 2_000): int
    {
        SegmentFixtures::owner()->transaction(function () use ($ctx, $amount): void {
            SegmentFixtures::owner()->insert(
                "INSERT INTO refunds (public_id, payment_id, provider, idempotency_key_hash, amount_minor, currency, status, requested_at, succeeded_at, created_at, updated_at)
                 VALUES (gen_random_uuid(), ?, 'cinetpay', md5(random()::text) || md5(random()::text), ?, 'XOF', 'succeeded', now(), now(), now(), now())",
                [$ctx['payment_id'], $amount],
            );

            SegmentFixtures::owner()->update(
                "UPDATE orders SET status = 'partially_refunded' WHERE id = ?",
                [$ctx['order_id']],
            );
        });

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM refunds ORDER BY id DESC LIMIT 1')->id;
    }

    public static function payout(int $affiliateId, int $policyId, int $amount, string $currency = 'XOF'): int
    {
        SegmentFixtures::owner()->insert(
            "INSERT INTO affiliate_payouts (public_id, affiliate_id, policy_id, status, amount_minor, currency, threshold_minor_snapshot, requested_at, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, ?, 'requested', ?, ?, 10000, now(), now(), now())",
            [$affiliateId, $policyId, $amount, $currency],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM affiliate_payouts ORDER BY id DESC LIMIT 1')->id;
    }

    public static function payoutItem(int $payoutId, int $commissionId, int $amount, string $currency = 'XOF', ?int $affiliateId = null): int
    {
        $affiliateId ??= (int) SegmentFixtures::owner()->selectOne(
            'SELECT affiliate_id FROM affiliate_payouts WHERE id = ?', [$payoutId],
        )->affiliate_id;

        SegmentFixtures::owner()->insert(
            'INSERT INTO affiliate_payout_items (payout_id, commission_id, affiliate_id, amount_minor, currency, created_at) VALUES (?, ?, ?, ?, ?, now())',
            [$payoutId, $commissionId, $affiliateId, $amount, $currency],
        );

        return (int) SegmentFixtures::owner()->selectOne('SELECT id FROM affiliate_payout_items ORDER BY id DESC LIMIT 1')->id;
    }
}
