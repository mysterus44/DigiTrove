<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_products', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->primary(['coupon_id', 'product_id']);
            $table->index('product_id', 'coupon_products_product_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_products');
    }
};
