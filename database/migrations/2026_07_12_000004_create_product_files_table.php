<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->text('storage_disk')->default('private');
            $table->text('storage_path');
            $table->text('original_name');
            $table->text('mime_type')->nullable();
            $table->bigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->text('version')->default('1.0');
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['product_id', 'is_active']);
        });

        DB::statement("ALTER TABLE product_files ADD CONSTRAINT product_files_storage_disk_private_check CHECK (storage_disk = 'private')");
        DB::statement('ALTER TABLE product_files ADD CONSTRAINT product_files_size_bytes_non_negative_check CHECK (size_bytes >= 0)');
        DB::statement("ALTER TABLE product_files ADD CONSTRAINT product_files_checksum_sha256_check CHECK (checksum_sha256 ~* '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE product_files ADD CONSTRAINT product_files_storage_path_private_check CHECK (storage_path !~* '^[a-z][a-z0-9+.-]*://' AND left(storage_path, 1) <> '/' AND storage_path NOT LIKE 'public/%')");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_files');
    }
};
