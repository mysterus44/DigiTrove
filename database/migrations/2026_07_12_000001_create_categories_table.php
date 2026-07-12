<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->text('slug');
            $table->text('name');
            $table->integer('position')->default(0);
            $table->timestampsTz();

            $table->index('parent_id', 'categories_parent_id_index');
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_slug_unique UNIQUE (slug)');
        DB::statement('ALTER TABLE categories ADD CONSTRAINT categories_parent_not_self_check CHECK (parent_id IS NULL OR parent_id <> id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
