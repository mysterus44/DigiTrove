<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P5-A0 — soft-linked analytics sessions (D-037).
 *
 * Session mutation and cookie creation remain deliberately disabled until
 * P5-A1. No transactional foreign key or raw network/cookie identifier lives
 * here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE analytics_sessions (
                id UUID PRIMARY KEY,
                visitor_id UUID NOT NULL,
                user_id BIGINT,
                started_at TIMESTAMPTZ NOT NULL,
                last_seen_at TIMESTAMPTZ NOT NULL,
                ended_at TIMESTAMPTZ,
                entry_path VARCHAR(2048) NOT NULL,
                exit_path VARCHAR(2048),
                page_views INTEGER NOT NULL DEFAULT 0,
                utm_source VARCHAR(255),
                utm_medium VARCHAR(255),
                utm_campaign VARCHAR(255),
                device_type VARCHAR(16),
                country_code VARCHAR(2),
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT analytics_sessions_time_order_check
                    CHECK (
                        last_seen_at >= started_at
                        AND (ended_at IS NULL OR ended_at >= last_seen_at)
                    ),
                CONSTRAINT analytics_sessions_created_after_start_check
                    CHECK (created_at >= started_at),
                CONSTRAINT analytics_sessions_updated_after_created_check
                    CHECK (updated_at >= created_at),
                CONSTRAINT analytics_sessions_page_views_non_negative_check
                    CHECK (page_views >= 0),
                CONSTRAINT analytics_sessions_entry_path_format_check
                    CHECK (
                        left(entry_path, 1) = '/'
                        AND left(entry_path, 2) <> '//'
                        AND strpos(entry_path, '://') = 0
                        AND strpos(entry_path, '?') = 0
                        AND strpos(entry_path, '#') = 0
                        AND strpos(entry_path, E'\r') = 0
                        AND strpos(entry_path, E'\n') = 0
                    ),
                CONSTRAINT analytics_sessions_exit_path_format_check
                    CHECK (
                        exit_path IS NULL OR (
                            left(exit_path, 1) = '/'
                            AND left(exit_path, 2) <> '//'
                            AND strpos(exit_path, '://') = 0
                            AND strpos(exit_path, '?') = 0
                            AND strpos(exit_path, '#') = 0
                            AND strpos(exit_path, E'\r') = 0
                            AND strpos(exit_path, E'\n') = 0
                        )
                    ),
                CONSTRAINT analytics_sessions_utm_source_format_check
                    CHECK (utm_source IS NULL OR utm_source ~ '^[a-z0-9][a-z0-9._-]*$'),
                CONSTRAINT analytics_sessions_utm_medium_format_check
                    CHECK (utm_medium IS NULL OR utm_medium ~ '^[a-z0-9][a-z0-9._-]*$'),
                CONSTRAINT analytics_sessions_utm_campaign_format_check
                    CHECK (utm_campaign IS NULL OR utm_campaign ~ '^[a-z0-9][a-z0-9._-]*$'),
                CONSTRAINT analytics_sessions_device_type_check
                    CHECK (device_type IS NULL OR device_type IN ('desktop', 'mobile', 'tablet', 'bot', 'other')),
                CONSTRAINT analytics_sessions_country_code_format_check
                    CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')
            );

            CREATE INDEX analytics_sessions_visitor_started_index
                ON analytics_sessions (visitor_id, started_at DESC);
            CREATE INDEX analytics_sessions_user_started_index
                ON analytics_sessions (user_id, started_at DESC)
                WHERE user_id IS NOT NULL;
            CREATE INDEX analytics_sessions_campaign_started_index
                ON analytics_sessions (utm_campaign, started_at DESC)
                WHERE utm_campaign IS NOT NULL;
            SQL);

        DB::statement('REVOKE ALL PRIVILEGES ON TABLE analytics_sessions FROM PUBLIC, digitrove_runtime');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS analytics_sessions');
    }
};
