<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('first_name')->nullable();
            $table->text('last_name')->nullable();
            $table->text('phone')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->text('locale')->default('fr');
            $table->boolean('marketing_consent')->default(false);
            $table->timestampTz('consent_updated_at')->nullable();
            $table->text('lifecycle_stage')->default('lead');
            $table->integer('orders_count')->default(0);
            $table->bigInteger('lifetime_value_minor')->default(0);
            $table->timestampTz('first_order_at')->nullable();
            $table->timestampTz('last_order_at')->nullable();
            $table->text('first_touch_source')->nullable();
            $table->text('first_touch_medium')->nullable();
            $table->text('first_touch_campaign')->nullable();
            $table->timestampsTz();

            $table->index('lifecycle_stage');
        });

        DB::statement("ALTER TABLE customer_profiles ADD CONSTRAINT customer_profiles_lifecycle_stage_check CHECK (lifecycle_stage IN ('lead', 'prospect', 'customer', 'repeat', 'churned'))");
        DB::statement('CREATE INDEX customer_profiles_last_order_at_desc_index ON customer_profiles (last_order_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
