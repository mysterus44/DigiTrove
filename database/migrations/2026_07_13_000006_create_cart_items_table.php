<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('quantity')->default(1);
            $table->timestampsTz();

            $table->unique(['cart_id', 'product_id'], 'cart_items_cart_product_unique');
            $table->index('product_id', 'cart_items_product_id_index');
        });

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_positive_check CHECK (quantity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
