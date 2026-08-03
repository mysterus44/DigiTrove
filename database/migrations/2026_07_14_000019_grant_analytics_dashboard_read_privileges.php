<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLE = 'digitrove_analytics_reader';

    private const ROLLUPS = 'public.daily_sales_stats, public.daily_product_stats, public.daily_product_engagement_stats, public.daily_funnel_stats';

    public function up(): void
    {
        $role = DB::selectOne(<<<'SQL'
            SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication,
                   rolbypassrls, rolinherit
            FROM pg_roles
            WHERE rolname = 'digitrove_analytics_reader'
            SQL);

        if ($role === null
            || ! $role->rolcanlogin
            || $role->rolsuper
            || $role->rolcreatedb
            || $role->rolcreaterole
            || $role->rolreplication
            || $role->rolbypassrls
            || $role->rolinherit) {
            throw new RuntimeException('P5-A3 requires the pre-provisioned restricted analytics reader role.');
        }

        if ((int) DB::scalar(<<<'SQL'
            SELECT count(*)
            FROM pg_auth_members m
            JOIN pg_roles member ON member.oid = m.member
            WHERE member.rolname = 'digitrove_analytics_reader'
            SQL) !== 0) {
            throw new RuntimeException('P5-A3 analytics reader must not be a member of another role.');
        }

        DB::statement('REVOKE CREATE ON SCHEMA public FROM '.self::ROLE);
        DB::statement('GRANT USAGE ON SCHEMA public TO '.self::ROLE);
        DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM '.self::ROLE);
        DB::statement('REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM '.self::ROLE);
        DB::statement('REVOKE ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA public FROM '.self::ROLE);
        DB::statement('GRANT SELECT ON TABLE '.self::ROLLUPS.' TO '.self::ROLE);
    }

    public function down(): void
    {
        DB::statement('REVOKE SELECT ON TABLE '.self::ROLLUPS.' FROM '.self::ROLE);
        DB::statement('REVOKE USAGE ON SCHEMA public FROM '.self::ROLE);
    }
};
