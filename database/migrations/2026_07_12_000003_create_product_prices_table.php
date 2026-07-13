<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('price_minor');
            $table->bigInteger('compare_at_price_minor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['product_id', 'currency']);
            $table->index(['currency', 'is_active']);
        });

        DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_currency_format_check CHECK (char_length(currency) = 3 AND currency = upper(currency))');
        DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_price_minor_non_negative_check CHECK (price_minor >= 0)');
        DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_compare_at_price_check CHECK (compare_at_price_minor IS NULL OR compare_at_price_minor >= price_minor)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
