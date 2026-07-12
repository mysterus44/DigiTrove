<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_category', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->primary(['product_id', 'category_id']);
            $table->index('category_id', 'product_category_category_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category');
    }
};
