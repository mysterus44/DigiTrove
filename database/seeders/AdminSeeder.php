<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\AdminCredentialPolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates the initial administrator from `ADMIN_EMAIL` / `ADMIN_PASSWORD` (H2.3).
 *
 * ⚠️ THIS IS NEW CODE, NOT A FIX. `.env.example` has advertised both variables since P1 and
 * **no file in the repository ever read them** — the tracker described a seeder that
 * refused an empty password, and it did not exist. Nothing here was ever "verified"; it had
 * to be written.
 *
 * ⚠️ IT DOES NOT USE `UserFactory`, DELIBERATELY. The factory defaults to
 * `role => Customer` and stamps `email_verified_at`, so leaning on it would have produced a
 * VERIFIED CUSTOMER while looking like success — a user row exists, the seeder returns
 * cleanly, and nobody can log into the panel. `role`, `status` and `email_verified_at` are
 * written explicitly for that reason.
 *
 * Refusal is loud and total: no default password, no silent skip, no partial write.
 */
final class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Read through config, NEVER `env()` directly. `php artisan config:cache` — which a
        // production deployment runs — makes `env()` return null outside config files, so a
        // seeder calling it would refuse on a correctly configured server: fail-closed for
        // entirely the wrong reason, blocking a legitimate deployment.
        $email = config('admin.email');
        $password = config('admin.password');

        // Throws with the rule that was broken, never echoing the password: seeder output
        // lands in CI logs and terminal scrollback.
        AdminCredentialPolicy::assertUsable($email, $password);

        $email = trim((string) $email);

        DB::transaction(function () use ($email, $password): void {
            // `users.email` is CITEXT, so the lookup is already case-insensitive and the
            // unique constraint cannot be defeated by capitalisation.
            $user = User::withTrashed()->where('email', $email)->first();

            $attributes = [
                'email' => $email,
                // The model's `setPasswordHashAttribute` hashes through `Hash::needsRehash`,
                // so the plaintext never reaches the column.
                'password_hash' => (string) $password,
                'role' => UserRole::Admin->value,
                'status' => UserStatus::Active->value,
            ];

            if ($user === null) {
                User::query()->create([...$attributes, 'email_verified_at' => now()]);

                $this->command?->info('Administrator created.');

                return;
            }

            // An existing row is REPAIRED rather than duplicated: `users.email` is unique, so
            // a second insert would fail, and re-running the seeder must be safe. Promoting
            // to admin, reactivating and restoring a soft-deleted account are all part of
            // "the operator asked for this account to be the administrator".
            $user->forceFill([
                ...$attributes,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'deleted_at' => null,
            ])->save();

            $this->command?->info('Administrator updated.');
        });
    }
}
