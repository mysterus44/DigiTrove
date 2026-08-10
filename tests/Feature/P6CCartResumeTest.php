<?php

declare(strict_types=1);

use App\Http\Controllers\CartResumeController;
use App\Mail\AbandonedCartReminder;
use App\Models\Product;
use App\Services\Crm\CartAbandonmentService;
use App\Services\Crm\CartReminderDispatcher;
use App\Services\Crm\CartReminderService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CartReminderFixtures as Cart;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.cart_reminders.detection_enabled' => true,
        'crm.cart_reminders.enqueue_enabled' => true,
        'crm.cart_reminders.send_enabled' => true,
        'crm.cart_reminders.inactivity_minutes' => 60,
        'crm.cart_reminders.batch_size' => 50,
        'crm.cart_reminders.max_step' => 3,
        'crm.cart_reminders.cooldown_minutes' => 60,
        'crm.cart_reminders.capability_ttl_minutes' => 1440,
        'crm.cart_reminders.retention_days' => 30,
        'mail.default' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587],
        'mail.from.address' => 'no-reply@digitrove.test',
    ]);
});

/**
 * Run the engine far enough to hold a real, sent capability.
 *
 * @return array{cart_id:int,public_id:string,capability:string,attempt_id:int}
 */
function p6cSentCapability(): array
{
    Mail::fake();

    $user = Cart::eligibleUser();
    Fx::grantMarketingConsent(Cart::contactFor($user->email));
    $cartId = Cart::cart(status: 'active', inactiveMinutes: 120, userId: (int) $user->id);
    Cart::addItem($cartId, (int) Product::factory()->create()->id);
    Cart::ageActivity($cartId, 120);

    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);
    app(CartReminderDispatcher::class)->dispatch($attempt['attempt_id']);

    $capability = null;
    Mail::assertSent(AbandonedCartReminder::class, function (AbandonedCartReminder $m) use (&$capability): bool {
        $capability = $m->capability;

        return true;
    });

    return [
        'cart_id' => $cartId,
        'public_id' => (string) Cart::row($cartId)->public_id,
        'capability' => (string) $capability,
        'attempt_id' => $attempt['attempt_id'],
    ];
}

// ── Bootstrap GET ───────────────────────────────────────────────────────────────

it('serves a bootstrap page that receives no secret and reveals nothing', function () {
    $ctx = p6cSentCapability();

    $response = test()->get('/cart/resume/'.$ctx['public_id']);

    $response->assertSuccessful()
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    $html = $response->getContent();

    // The capability is in the FRAGMENT, so the server never received it — and the page
    // must not contain it, the cart's owner, or any hint the cart exists.
    expect($html)->not->toContain($ctx['capability'])
        ->and($html)->not->toContain('@')
        ->and($html)->toContain('history.replaceState');
});

it('serves the same bootstrap page for a cart that does not exist', function () {
    $real = test()->get('/cart/resume/'.p6cSentCapability()['public_id'])->getContent();
    $fake = test()->get('/cart/resume/'.Str::uuid()->toString())->getContent();

    // The bootstrap page is DB-free, so an unknown id is indistinguishable. The CSP
    // nonce and the CSRF token legitimately differ per response and are normalised
    // away; everything else must match byte for byte.
    $normalise = static fn (string $html): string => preg_replace(
        ['/nonce="[^"]+"/', '/content="[^"]{20,}"/', '/[0-9a-f-]{36}/'],
        ['nonce="N"', 'content="T"', 'ID'],
        $html,
    );

    expect(strlen($fake))->toBeGreaterThan(0)
        ->and($normalise($fake))->toBe($normalise($real));
});

it('sets a per-response CSP with no unsafe-inline and no third party', function () {
    $csp = (string) test()->get('/cart/resume/'.Str::uuid()->toString())->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'none'")
        ->and($csp)->toContain("connect-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain('nonce-')
        ->and($csp)->not->toContain('unsafe-inline')
        ->and($csp)->not->toContain('unsafe-eval')
        ->and($csp)->not->toContain('http');
});

// ── Fragment bridge CONTRACT (static; no browser is executed) ────────────────────

it('states the fragment bridge contract in the shipped script', function () {
    $view = file_get_contents(resource_path('views/carts/resume.blade.php'));

    // The order is the security property: erase the fragment BEFORE using it.
    $erase = strpos($view, 'history.replaceState');
    $use = strpos($view, "URLSearchParams(fragment)");
    expect($erase)->toBeLessThan($use);

    expect($view)->toContain("location.hash")
        ->toContain("method: 'POST'")
        ->toContain('X-CSRF-TOKEN')
        ->toContain('/^[0-9a-f]{64}$/');

    // No browser storage, no console, no third party, no query string.
    foreach (['localStorage', 'sessionStorage', 'document.cookie', 'console.', 'http://', 'https://', '?c='] as $forbidden) {
        expect($view)->not->toContain($forbidden);
    }
});

// ── Redemption POST ─────────────────────────────────────────────────────────────

it('redeems a valid capability and establishes an opaque session continuation', function () {
    $ctx = p6cSentCapability();

    $response = test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => $ctx['capability']]);

    $response->assertRedirect('/cart/resumed');

    // The continuation is a SESSION reference, not the raw capability in a URL.
    expect(session(CartResumeController::SESSION_KEY))->toBe($ctx['public_id'])
        ->and($response->headers->get('Location'))->not->toContain($ctx['capability']);

    test()->get('/cart/resumed')->assertSuccessful()->assertSee('retrouvé', false);
});

it('refuses every failure mode with one identical response', function () {
    $ctx = p6cSentCapability();
    $bodies = [];

    $cases = [
        'absent capability' => ['cart' => $ctx['public_id']],
        'absent cart' => ['c' => $ctx['capability']],
        'malformed capability' => ['cart' => $ctx['public_id'], 'c' => 'nothex'],
        'oversized capability' => ['cart' => $ctx['public_id'], 'c' => str_repeat('a', 5000)],
        'wrong capability' => ['cart' => $ctx['public_id'], 'c' => str_repeat('b', 64)],
        'unknown cart' => ['cart' => Str::uuid()->toString(), 'c' => $ctx['capability']],
        'malformed cart' => ['cart' => 'not-a-uuid', 'c' => $ctx['capability']],
    ];

    foreach ($cases as $label => $payload) {
        $response = test()->post('/cart/resume', $payload);
        $response->assertNotFound();
        $bodies[$label] = $response->getContent();

        expect(session(CartResumeController::SESSION_KEY))->toBeNull("{$label} must establish nothing");
    }

    // Byte-for-byte identical: the response cannot be used as an oracle.
    expect(array_unique(array_values($bodies)))->toHaveCount(1);
});

/**
 * A valid capability bound to another cart must not resume THIS one.
 */
it('refuses a capability presented against a different cart', function () {
    $first = p6cSentCapability();
    $second = p6cSentCapability();

    test()->post('/cart/resume', ['cart' => $second['public_id'], 'c' => $first['capability']])
        ->assertNotFound();

    expect(session(CartResumeController::SESSION_KEY))->toBeNull();
});

it('refuses an expired capability', function () {
    $ctx = p6cSentCapability();

    // Age the send past the configured TTL. The TTL is enforced by the authority, so a
    // runtime that wanted to widen it could not.
    Fx::owner()->update(
        "UPDATE cart_reminder_attempts SET sent_at = now() - interval '3 days' WHERE id = ?",
        [$ctx['attempt_id']],
    );

    test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => $ctx['capability']])
        ->assertNotFound();
});

it('refuses a revoked capability', function () {
    $ctx = p6cSentCapability();

    // Suppression clears the digest: revocation is the absence of the secret.
    Fx::owner()->update('UPDATE cart_reminder_attempts SET secret_hash = NULL WHERE id = ?', [$ctx['attempt_id']]);

    test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => $ctx['capability']])
        ->assertNotFound();
});

it('refuses once the cart is converted or expired', function () {
    foreach (['converted', 'expired'] as $status) {
        $ctx = p6cSentCapability();
        Fx::owner()->update('UPDATE carts SET status = ? WHERE id = ?', [$status, $ctx['cart_id']]);

        test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => $ctx['capability']])
            ->assertNotFound();
    }
});

it('refuses when reminder sending is disabled', function () {
    $ctx = p6cSentCapability();
    config(['crm.cart_reminders.send_enabled' => false]);

    test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => $ctx['capability']])
        ->assertNotFound();

    expect(session(CartResumeController::SESSION_KEY))->toBeNull();
});

/**
 * The capability is TTL-bounded and repeatable within its window, not one-time: a
 * customer who clicks the same mail twice must not be locked out. Repeating it yields
 * the SAME continuation, so no second capability is ever created.
 */
it('is repeatable within its TTL and always yields the same continuation', function () {
    $ctx = p6cSentCapability();

    test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => $ctx['capability']])
        ->assertRedirect('/cart/resumed');
    $first = session(CartResumeController::SESSION_KEY);

    test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => $ctx['capability']])
        ->assertRedirect('/cart/resumed');

    expect(session(CartResumeController::SESSION_KEY))->toBe($first)
        // Still exactly one attempt: redemption creates no ledger row.
        ->and((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM cart_reminder_attempts')->c)->toBe(1);
});

it('never puts the capability in a log, an exception or the session', function () {
    $ctx = p6cSentCapability();

    test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => $ctx['capability']]);

    // The session holds the cart's PUBLIC id, never the capability.
    expect(json_encode(session()->all()))->not->toContain($ctx['capability']);

    $controller = file_get_contents(app_path('Http/Controllers/CartResumeController.php'));
    foreach (['Log::', 'report(', 'dd(', 'dump(', 'var_dump'] as $forbidden) {
        expect($controller)->not->toContain($forbidden);
    }
});

it('rate limits redemption attempts', function () {
    $ctx = p6cSentCapability();

    for ($i = 0; $i < 20; $i++) {
        test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => str_repeat('b', 64)]);
    }

    test()->post('/cart/resume', ['cart' => $ctx['public_id'], 'c' => str_repeat('b', 64)])
        ->assertStatus(429);
});

it('adds no generic cart route or controller', function () {
    $uris = collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->map(fn ($r): string => strtolower($r->uri()))
        ->filter(fn (string $u): bool => str_contains($u, 'cart'))
        ->values()
        ->all();

    sort($uris);

    // EXACTLY the three P6-C resume URIs. No storefront, no cart API, no CRUD.
    expect($uris)->toBe(['cart/resume', 'cart/resume/{cartpublicid}', 'cart/resumed']);

    expect(glob(app_path('Http/Controllers/CartController.php')) ?: [])->toBe([]);
});
