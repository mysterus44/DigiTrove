<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->text('slug');
            $table->text('name');
            $table->text('type');
            $table->text('status')->default('draft');
            $table->text('short_description')->nullable();
            $table->text('long_description')->nullable();
            $table->text('cover_image_path')->nullable();
            $table->text('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->integer('sales_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_slug_unique UNIQUE (slug)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_type_check CHECK (type IN ('software', 'course', 'ebook', 'bundle', 'template'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_sales_count_non_negative_check CHECK (sales_count >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_rating_count_non_negative_check CHECK (rating_count >= 0)');

        Schema::table('products', function (Blueprint $table): void {
            $table->index(['status', 'published_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
