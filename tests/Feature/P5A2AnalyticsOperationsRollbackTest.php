<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PhaseMigrationHarness;

const P5A2_MIGRATION = '2026_07_14_000018_create_analytics_operations_authority.php';

it('rolls back only the P5-A2 boundary and restores the exact P5-A1 analytics schema', function () {
    $harness = new PhaseMigrationHarness('digitrove_p5a2_rollback_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $applied = $harness->applyMigrationsThrough(P5A2_MIGRATION);

        expect($applied)->toHaveCount(34)
            ->and(end($applied))->toBe('2026_07_14_000018_create_analytics_operations_authority')
            ->and($harness->hasTable('daily_product_engagement_stats'))->toBeTrue()
            ->and($harness->ownerPdo()->query(
                "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='order_items' AND column_name='purchased_product_id'",
            )->fetchColumn())->toBe(1);

        $worker = $harness->analyticsWorkerPdo();
        $partitionMonth = now('UTC')->startOfMonth()->addMonths(9)->toDateString();
        $partitionName = 'events_y'.now('UTC')->startOfMonth()->addMonths(9)->format('Y').'m'.now('UTC')->startOfMonth()->addMonths(9)->format('m');
        $statement = $worker->prepare('SELECT public.ensure_analytics_events_month_partition(:month::date)');
        $statement->execute(['month' => $partitionMonth]);

        expect($harness->rollbackExactMigrations([P5A2_MIGRATION]))->toBe([
            '2026_07_14_000018_create_analytics_operations_authority',
        ])
            ->and($harness->hasTable('daily_product_engagement_stats'))->toBeFalse()
            ->and($harness->hasTable('daily_product_stats'))->toBeTrue()
            ->and($harness->hasTable('events'))->toBeTrue()
            ->and($harness->hasTable('analytics_sessions'))->toBeTrue()
            ->and($harness->ownerPdo()->query(
                "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='daily_product_stats' AND column_name IN ('views', 'add_to_carts')",
            )->fetchColumn())->toBe(2)
            ->and($harness->ownerPdo()->query(
                "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='order_items' AND column_name='purchased_product_id'",
            )->fetchColumn())->toBe(0)
            ->and($harness->countFunctions(['ingest_first_party_analytics_event']))->toBe(1)
            ->and($harness->countFunctions([
                'refresh_authoritative_daily_analytics',
                'ensure_analytics_events_month_partition',
                'audit_analytics_event_partitions',
            ]))->toBe(0)
            ->and($harness->hasTable($partitionName))->toBeTrue()
            ->and($harness->ranMigrations())->toHaveCount(33);
    } finally {
        $harness->drop();
    }

    expect(DB::connection('pgsql_migration')->table('pg_database')->where('datname', $harness->databaseName())->exists())->toBeFalse();
});

it('backfills the purchased identity from every resolvable historical order item', function () {
    $harness = new PhaseMigrationHarness('digitrove_p5a2_backfill_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000017_create_analytics_ingestion_authority.php');
        $pdo = $harness->ownerPdo();
        $pdo->exec(<<<'SQL'
            BEGIN;
            INSERT INTO products (slug, name, type, status, sales_count, rating_avg, rating_count, created_at, updated_at)
            VALUES ('p5a2-backfill', 'P5A2 Backfill', 'ebook', 'published', 0, 0, 0, now(), now());
            INSERT INTO orders (
                public_id, order_number, checkout_idempotency_hash, customer_email,
                subtotal_minor, discount_minor, tax_minor, total_minor, currency,
                status, placed_at, expires_at, created_at, updated_at
            )
            VALUES (
                gen_random_uuid(), 'DGT-2026-A2BF000001', repeat('a', 64), 'p5a2@example.test',
                1000, 0, 0, 1000, 'XOF',
                'pending', now(), now() + interval '30 minutes', now(), now()
            );
            INSERT INTO order_items (
                order_id, product_id, product_name_snapshot, product_slug_snapshot,
                product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor,
                line_discount_minor, line_total_minor, currency, created_at, updated_at
            )
            SELECT o.id, p.id, p.name, p.slug, p.type, 1000, 1, 1000, 0, 1000, 'XOF', now(), now()
            FROM orders o, products p
            WHERE o.order_number = 'DGT-2026-A2BF000001' AND p.slug = 'p5a2-backfill';
            SET CONSTRAINTS ALL IMMEDIATE;
            COMMIT;
            SQL);

        $harness->applyExactMigrations([P5A2_MIGRATION]);

        expect($pdo->query('SELECT COUNT(*) FROM order_items WHERE purchased_product_id = product_id')->fetchColumn())->toBe(1)
            ->and($pdo->query('SELECT COUNT(*) FROM order_items WHERE purchased_product_id IS NULL')->fetchColumn())->toBe(0);
    } finally {
        $harness->drop();
    }
});

it('refuses an impossible historical product identity instead of inventing a sentinel', function () {
    $harness = new PhaseMigrationHarness('digitrove_p5a2_impossible_'.strtolower(Str::random(10)));

    try {
        $harness->create();
        $harness->applyMigrationsThrough('2026_07_14_000017_create_analytics_ingestion_authority.php');
        $pdo = $harness->ownerPdo();
        $pdo->exec(<<<'SQL'
            BEGIN;
            INSERT INTO products (slug, name, type, status, sales_count, rating_avg, rating_count, created_at, updated_at)
            VALUES ('p5a2-impossible', 'P5A2 Impossible', 'ebook', 'published', 0, 0, 0, now(), now());
            INSERT INTO orders (
                public_id, order_number, checkout_idempotency_hash, customer_email,
                subtotal_minor, discount_minor, tax_minor, total_minor, currency,
                status, placed_at, expires_at, created_at, updated_at
            )
            VALUES (
                gen_random_uuid(), 'DGT-2026-A2HM000001', repeat('b', 64), 'p5a2-impossible@example.test',
                1000, 0, 0, 1000, 'XOF',
                'pending', now(), now() + interval '30 minutes', now(), now()
            );
            INSERT INTO order_items (
                order_id, product_id, product_name_snapshot, product_slug_snapshot,
                product_type_snapshot, unit_price_minor, quantity, line_subtotal_minor,
                line_discount_minor, line_total_minor, currency, created_at, updated_at
            )
            SELECT o.id, p.id, p.name, p.slug, p.type, 1000, 1, 1000, 0, 1000, 'XOF', now(), now()
            FROM orders o, products p
            WHERE o.order_number = 'DGT-2026-A2HM000001' AND p.slug = 'p5a2-impossible';
            SET CONSTRAINTS ALL IMMEDIATE;
            DELETE FROM products WHERE slug = 'p5a2-impossible';
            COMMIT;
            SQL);

        expect((int) $pdo->query('SELECT COUNT(*) FROM order_items WHERE product_id IS NULL')->fetchColumn())->toBe(1);

        expect(fn () => $harness->applyExactMigrations([P5A2_MIGRATION]))
            ->toThrow(RuntimeException::class, 'P5-A2 cannot backfill purchased product identity');
    } finally {
        $harness->drop();
    }
});
