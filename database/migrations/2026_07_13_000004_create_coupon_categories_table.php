<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_categories', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->primary(['coupon_id', 'category_id']);
            $table->index('category_id', 'coupon_categories_category_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_categories');
    }
};
