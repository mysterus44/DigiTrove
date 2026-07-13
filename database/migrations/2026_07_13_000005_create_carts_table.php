<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->text('secret_hash');
            $table->foreignUuid('visitor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->nullable();
            $table->text('status')->default('active');
            $table->timestampTz('expires_at');
            $table->timestampTz('abandoned_at')->nullable();
            $table->timestampsTz();

            $table->unique('public_id', 'carts_public_id_unique');
            $table->unique('secret_hash', 'carts_secret_hash_unique');
            $table->index(['status', 'expires_at'], 'carts_status_expires_at_index');
            $table->index('user_id', 'carts_user_id_index');
            $table->index('visitor_id', 'carts_visitor_id_index');
            $table->index('coupon_id', 'carts_coupon_id_index');
        });

        DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_secret_hash_format_check CHECK (secret_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE carts ADD CONSTRAINT carts_currency_format_check CHECK (currency IS NULL OR (char_length(currency) = 3 AND currency = upper(currency)))');
        DB::statement("ALTER TABLE carts ADD CONSTRAINT carts_status_check CHECK (status IN ('active', 'converted', 'abandoned', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
