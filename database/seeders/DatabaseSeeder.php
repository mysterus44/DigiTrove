<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * ⚠️ H2.4 — THE DISPOSABLE ACCOUNT IS GATED, THE ADMINISTRATOR IS NOT.
     *
     * `test@example.com` came from `UserFactory`, which stamps `Hash::make('password')`,
     * `email_verified_at => now()` and `status => Active`. Running `php artisan db:seed` in
     * production therefore created a REAL, VERIFIED, ACTIVE customer account whose password
     * is written in the framework's own source. Not an admin, so not a takeover — but a
     * genuine account with a public password, created by a command an operator runs without
     * a second thought.
     *
     * It now exists only where a disposable account is the point. The environment check is
     * explicit rather than implied by `APP_DEBUG` or by anyone's discipline.
     */
    public function run(): void
    {
        $this->call(AdminSeeder::class);

        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Skipping the disposable test account outside local/testing.');

            return;
        }

        User::factory()->create([
            'email' => 'test@example.com',
        ]);
    }
}
