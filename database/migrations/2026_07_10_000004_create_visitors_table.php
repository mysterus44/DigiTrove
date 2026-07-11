<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestampTz('last_seen_at')->useCurrent();
            $table->text('first_touch_source')->nullable();
            $table->text('first_touch_medium')->nullable();
            $table->text('first_touch_campaign')->nullable();
            $table->text('first_landing_page')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->timestampsTz();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
