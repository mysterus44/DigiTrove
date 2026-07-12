<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_bundles', function (Blueprint $table): void {
            $table->foreignId('bundle_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('child_product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('position')->default(0);

            $table->primary(['bundle_id', 'child_product_id']);
        });

        DB::statement('ALTER TABLE product_bundles ADD CONSTRAINT product_bundles_no_self_inclusion_check CHECK (bundle_id <> child_product_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bundles');
    }
};
