<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->text('email');
            $table->text('password_hash');
            $table->text('role')->default('customer');
            $table->text('status')->default('active');
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->softDeletesTz();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE CITEXT');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('customer', 'admin', 'staff'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'suspended', 'blocked'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
