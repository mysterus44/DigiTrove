<?php

declare(strict_types=1);

use App\Models\DownloadGrant;
use App\Models\DownloadLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

function p4c4RawToken(string $seed = 'grant'): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $seed, true)), '+/', '-_'), '=');
}

function p4c4Configure(): void
{
    config([
        'app.env' => 'testing',
        'delivery.enabled' => true,
        'delivery.attempt.ttl_seconds' => 900,
        'delivery.log.retention_days' => 90,
        'delivery.audit.ip_hash_key' => str_repeat('k', 32),
        'delivery.audit.ip_hash_key_version' => 1,
        'delivery.rate_limit.authorize_per_minute' => 10,
        'delivery.rate_limit.file_per_minute' => 60,
        'delivery.private_disks' => ['private'],
    ]);
}

/**
 * @return array{grant: DownloadGrant, raw_token: string}
 */
function p4c4Grant(string $seed = 'grant', array $overrides = []): array
{
    $rawToken = p4c4RawToken($seed);
    $grant = DownloadGrant::factory()->create([
        'token_hash' => hash('sha256', $rawToken),
        'max_downloads' => 3,
        ...$overrides,
    ]);

    $file = $grant->productFile()->firstOrFail();
    Storage::disk('private')->put($file->storage_path, str_repeat('x', (int) $file->size_bytes));

    return ['grant' => $grant, 'raw_token' => $rawToken];
}

function p4c4Authorize(DownloadGrant $grant, string $rawToken)
{
    return test()->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
        ->withHeader('Authorization', 'Bearer '.$rawToken)
        ->postJson("/api/downloads/{$grant->public_id}/authorize");
}

beforeEach(function (): void {
    p4c4Configure();
    Storage::fake('private');
});

it('serves one database-free exchange page for known and unknown public ids', function (): void {
    ['grant' => $grant, 'raw_token' => $rawToken] = p4c4Grant();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $known = $this->get("/downloads/{$grant->public_id}#token={$rawToken}");
    $unknown = $this->get('/downloads/00000000-0000-4000-8000-000000000000#token=fake');

    expect($queries)->toBe(0);
    $known->assertOk();
    $unknown->assertOk();
    expect($known->getContent())->not->toContain($rawToken)
        ->and($unknown->getContent())->not->toContain('fake')
        ->and($known->getContent())->toContain('history.replaceState')
        ->and($known->getContent())->toContain('Authorization')
        ->and($known->getContent())->not->toContain('localStorage')
        ->and($known->getContent())->not->toContain('sessionStorage')
        ->and($known->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($known->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('refuses query credentials and malformed authorization uniformly', function (): void {
    ['grant' => $grant, 'raw_token' => $rawToken] = p4c4Grant();

    $responses = [
        $this->postJson("/api/downloads/{$grant->public_id}/authorize?token={$rawToken}"),
        $this->postJson("/api/downloads/{$grant->public_id}/authorize"),
        $this->withHeader('Authorization', 'Basic '.$rawToken)
            ->postJson("/api/downloads/{$grant->public_id}/authorize"),
        $this->withHeader('Authorization', 'Bearer short')
            ->postJson("/api/downloads/{$grant->public_id}/authorize"),
    ];

    foreach ($responses as $response) {
        $response->assertNotFound()
            ->assertExactJson(['message' => 'Download unavailable.'])
            ->assertCookieMissing('dl_attempt');
    }
});

it('returns the same public refusal for unknown, false, expired, revoked, exhausted and unavailable grants', function (): void {
    ['grant' => $valid, 'raw_token' => $validToken] = p4c4Grant('valid');
    ['grant' => $expired, 'raw_token' => $expiredToken] = p4c4Grant('expired', [
        'created_at' => now()->subDays(2),
        'expires_at' => now()->subDay(),
    ]);
    ['grant' => $revoked, 'raw_token' => $revokedToken] = p4c4Grant('revoked');
    DB::table('download_grants')->where('id', $revoked->id)->update([
        'revoked_at' => now(),
        'revoked_reason_code' => 'manual_security_reissue',
    ]);
    ['grant' => $exhausted, 'raw_token' => $exhaustedToken] = p4c4Grant('exhausted', ['max_downloads' => 1]);
    DownloadLog::factory()->forGrant($exhausted)->create();
    ['grant' => $missingFile, 'raw_token' => $missingFileToken] = p4c4Grant('missing-file');
    Storage::disk('private')->delete($missingFile->productFile()->value('storage_path'));

    $responses = [
        $this->withHeader('Authorization', 'Bearer '.$validToken)
            ->postJson('/api/downloads/00000000-0000-4000-8000-000000000000/authorize'),
        p4c4Authorize($valid, p4c4RawToken('wrong')),
        p4c4Authorize($expired, $expiredToken),
        p4c4Authorize($revoked, $revokedToken),
        p4c4Authorize($exhausted, $exhaustedToken),
        p4c4Authorize($missingFile, $missingFileToken),
    ];

    foreach ($responses as $response) {
        $response->assertNotFound()
            ->assertExactJson(['message' => 'Download unavailable.'])
            ->assertCookieMissing('dl_attempt');
    }
});

it('creates one dedicated attempt, stores only its digest and lets G5 consume exactly once', function (): void {
    ['grant' => $grant, 'raw_token' => $rawToken] = p4c4Grant();

    $response = p4c4Authorize($grant, $rawToken);

    $response->assertOk()
        ->assertJsonPath('download_url', "/downloads/{$grant->public_id}/file")
        ->assertCookie('dl_attempt');

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($candidate): bool => $candidate->getName() === 'dl_attempt');
    $rawAttemptToken = $response->getCookie('dl_attempt')->getValue();

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower($cookie->getSameSite()))->toBe('strict')
        ->and($cookie->getPath())->toBe("/downloads/{$grant->public_id}/file")
        ->and($rawAttemptToken)->not->toBe($rawToken)
        ->and($rawAttemptToken)->toMatch('/\A[A-Za-z0-9_-]{43}\z/');

    $log = DownloadLog::query()->where('download_grant_id', $grant->id)->sole();
    expect($log->status)->toBe('started')
        ->and($log->quota_consumed)->toBeTrue()
        ->and($log->attempt_token_hash)->toBe(hash('sha256', $rawAttemptToken))
        ->and($log->attempt_token_hash)->not->toBe(hash('sha256', $rawToken))
        ->and($log->ip_hash)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($log->ip_hash_key_version)->toBe(1)
        ->and(DownloadGrant::query()->findOrFail($grant->id)->downloads_count)->toBe(1);

    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain($rawToken)
        ->and(json_encode($log->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($rawAttemptToken);
});

it('refuses a replay while an authenticated attempt is reusable without consuming again', function (): void {
    ['grant' => $grant, 'raw_token' => $rawToken] = p4c4Grant();

    p4c4Authorize($grant, $rawToken)->assertOk();
    p4c4Authorize($grant, $rawToken)
        ->assertNotFound()
        ->assertExactJson(['message' => 'Download unavailable.'])
        ->assertCookieMissing('dl_attempt');

    expect(DownloadLog::query()->where('download_grant_id', $grant->id)->count())->toBe(1)
        ->and(DownloadGrant::query()->findOrFail($grant->id)->downloads_count)->toBe(1);
});

it('marks the attempt cookie secure outside local and testing', function (): void {
    config(['app.env' => 'production', 'delivery.require_https' => true]);
    ['grant' => $grant, 'raw_token' => $rawToken] = p4c4Grant();

    $response = p4c4Authorize($grant, $rawToken);
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($candidate): bool => $candidate->getName() === 'dl_attempt');

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue();
});
