<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->text('code');
            $table->text('discount_type');
            $table->integer('percent_basis_points')->nullable();
            $table->integer('max_redemptions')->nullable();
            $table->integer('redemptions_count')->default(0);
            $table->integer('max_redemptions_per_customer')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE coupons ALTER COLUMN code TYPE CITEXT');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_code_unique UNIQUE (code)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_code_not_blank_check CHECK (length(btrim(code::text)) > 0)');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_discount_type_check CHECK (discount_type IN ('percent', 'fixed'))");
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_discount_value_check CHECK ((discount_type = 'percent' AND percent_basis_points BETWEEN 1 AND 10000) OR (discount_type = 'fixed' AND percent_basis_points IS NULL))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_max_redemptions_positive_check CHECK (max_redemptions IS NULL OR max_redemptions > 0)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_redemptions_count_non_negative_check CHECK (redemptions_count >= 0)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_max_redemptions_per_customer_positive_check CHECK (max_redemptions_per_customer IS NULL OR max_redemptions_per_customer > 0)');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_dates_order_check CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at >= starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
