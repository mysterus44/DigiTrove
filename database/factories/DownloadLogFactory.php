<?php

namespace Database\Factories;

use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds download attempts that satisfy G5 instead of bypassing it.
 *
 * The default state is a valid `started` attempt on a freshly issued deliverable
 * grant. BEWARE: persisting it REALLY consumes one quota unit — G5 pairs the
 * insertion with the exact +1 of the grant counter, exactly as production will.
 * Digests are derived from throwaway random values and the raw secrets are
 * dropped immediately; no raw token, secret or IP is ever persisted, kept as a
 * model property or logged. `completed` (and a consuming denial) can NEVER be
 * inserted directly: create `started`, then perform the authorised transition.
 * Attempt expiry and retention are explicit test values, never a hidden
 * commercial policy (D-029.5: no business DEFAULT, 365 days is configuration).
 *
 * @extends Factory<DownloadLog>
 */
class DownloadLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'download_grant_id' => fn (): int => DownloadGrant::factory()->create()->getKey(),
            'status' => 'started',
            'quota_consumed' => true,
            // Digest of a dedicated CSPRNG attempt secret; hashed and dropped.
            'attempt_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'attempt_expires_at' => now()->addMinutes(30),
            'denial_reason_code' => null,
            // HMAC-SHA-256 pseudonym of a throwaway IP; the key never enters tests.
            'ip_hash' => hash_hmac('sha256', fake()->ipv4(), bin2hex(random_bytes(16))),
            'ip_hash_key_version' => 1,
            'user_agent' => null,
            'bytes_sent' => null,
            'terminal_at' => null,
            'retention_until' => now()->addDays(30),
            // Explicit application clock: PHP timestamps serialise at whole-second
            // precision, so mixing them with the PostgreSQL DEFAULT would let a
            // `terminal_at >= created_at` comparison flake across the second edge.
            'created_at' => now(),
        ];
    }

    /**
     * Log against an existing grant. The caller owns the grant lifecycle and
     * must account for the quota unit a `started` row consumes.
     */
    public function forGrant(DownloadGrant $grant): static
    {
        return $this->state(fn (array $attributes) => [
            'download_grant_id' => $grant->getKey(),
        ]);
    }

    /**
     * A documented refusal on a KNOWN grant, before any consumption: no attempt
     * secret ever existed, no quota unit is consumed, no counter moves.
     */
    public function deniedDirectly(string $reasonCode = 'authorization_denied'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'denied',
            'quota_consumed' => false,
            'attempt_token_hash' => null,
            'attempt_expires_at' => null,
            'denial_reason_code' => $reasonCode,
            'terminal_at' => now(),
        ]);
    }
}
