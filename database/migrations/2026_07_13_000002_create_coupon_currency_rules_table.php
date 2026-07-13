<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_currency_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('fixed_amount_minor')->nullable();
            $table->bigInteger('min_order_minor')->default(0);
            $table->bigInteger('max_discount_minor')->nullable();
            $table->timestampsTz();

            $table->unique(['coupon_id', 'currency'], 'coupon_currency_rules_coupon_currency_unique');
        });

        DB::statement('ALTER TABLE coupon_currency_rules ADD CONSTRAINT coupon_currency_rules_currency_format_check CHECK (char_length(currency) = 3 AND currency = upper(currency))');
        DB::statement('ALTER TABLE coupon_currency_rules ADD CONSTRAINT coupon_currency_rules_fixed_amount_non_negative_check CHECK (fixed_amount_minor IS NULL OR fixed_amount_minor >= 0)');
        DB::statement('ALTER TABLE coupon_currency_rules ADD CONSTRAINT coupon_currency_rules_min_order_non_negative_check CHECK (min_order_minor >= 0)');
        DB::statement('ALTER TABLE coupon_currency_rules ADD CONSTRAINT coupon_currency_rules_max_discount_non_negative_check CHECK (max_discount_minor IS NULL OR max_discount_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_currency_rules');
    }
};
