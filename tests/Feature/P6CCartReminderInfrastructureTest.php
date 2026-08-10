<?php

declare(strict_types=1);

use App\Mail\AbandonedCartReminder;
use App\Models\Product;
use App\Services\Cart\CartAbandonmentService;
use App\Services\Cart\CartReminderDispatcher;
use App\Services\Cart\CartReminderService;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CartReminderFixtures as Cart;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * P6-C INFRASTRUCTURE integration.
 *
 * This proves the reminder ENGINE works end to end against real PostgreSQL. It does NOT
 * prove a storefront: the application has no cart creation or mutation flow, so the cart
 * is built by fixture. That gap is the precondition D-056 records, and this test is
 * named to make sure nobody later mistakes it for storefront coverage.
 */
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
        // A transport that can genuinely deliver; Mail::fake intercepts before any I/O.
        'mail.default' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587],
        'mail.from.address' => 'no-reply@digitrove.test',
    ]);
});

/** Build an abandoned cart owned by a consenting, verified customer. */
function p6cReadyCart(bool $withConsent = true): array
{
    $user = Cart::eligibleUser();
    $contactId = Cart::contactFor($user->email);

    if ($withConsent) {
        Fx::grantMarketingConsent($contactId);
    }

    $cartId = Cart::cart(status: 'active', inactiveMinutes: 120, userId: (int) $user->id);
    Cart::addItem($cartId, (int) Product::factory()->create()->id);
    // The item INSERT bumps last_activity_at through the trigger, so age it again.
    Cart::ageActivity($cartId, 120);

    return ['user' => $user, 'cart_id' => $cartId, 'contact_id' => $contactId];
}

it('runs the whole engine: detect, enqueue, send and resolve the capability', function () {
    Mail::fake();
    ['user' => $user, 'cart_id' => $cartId] = p6cReadyCart();

    // 1. Detection marks it abandoned.
    expect(app(CartAbandonmentService::class)->detect())->toBe([$cartId]);

    // 2. It becomes a candidate for step 1 and is enqueued exactly once.
    $candidates = app(CartReminderService::class)->candidates(null, 1);
    expect(collect($candidates)->pluck('cart_id')->all())->toBe([$cartId]);

    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);
    expect($attempt['created'])->toBeTrue()
        ->and($attempt['status'])->toBe('pending');

    // 3. The worker sends it.
    expect(app(CartReminderDispatcher::class)->dispatch($attempt['attempt_id']))->toBe('sent');

    Mail::assertSent(AbandonedCartReminder::class, fn (AbandonedCartReminder $m): bool => $m->cartPublicId === Cart::row($cartId)->public_id);

    // 4. The capability resolves — and ONLY through its digest.
    $capability = null;
    Mail::assertSent(AbandonedCartReminder::class, function (AbandonedCartReminder $m) use (&$capability): bool {
        $capability = $m->capability;

        return true;
    });

    expect($capability)->toMatch('/\A[0-9a-f]{64}\z/');

    $resolved = app(CartReminderService::class)->resolveBySecret(CartReminderDispatcher::digest($capability));
    expect($resolved)->not->toBeNull()
        ->and($resolved['cart_id'])->toBe($cartId);

    // The raw capability is NOWHERE at rest: only its digest is stored.
    $row = Cart::attempt($attempt['attempt_id']);
    expect($row->secret_hash)->toBe(CartReminderDispatcher::digest($capability))
        ->and($row->secret_hash)->not->toBe($capability)
        ->and($row->status)->toBe('sent');
});

it('sends nothing when consent was withdrawn after the attempt was queued', function () {
    Mail::fake();
    ['cart_id' => $cartId, 'contact_id' => $contactId] = p6cReadyCart();

    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);

    // The customer withdraws between queuing and sending — a window of hours in reality.
    Cart::withdrawConsent($contactId);

    expect(app(CartReminderDispatcher::class)->dispatch($attempt['attempt_id']))->toBe('suppressed');

    Mail::assertNothingSent();
    expect(Cart::attempt($attempt['attempt_id'])->terminal_reason)->toBe('consent_withdrawn');
});

it('sends nothing when the cart converted after the attempt was queued', function () {
    Mail::fake();
    ['cart_id' => $cartId] = p6cReadyCart();

    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);

    Fx::owner()->update("UPDATE carts SET status = 'converted' WHERE id = ?", [$cartId]);

    expect(app(CartReminderDispatcher::class)->dispatch($attempt['attempt_id']))->toBe('suppressed');

    Mail::assertNothingSent();
    expect(Cart::attempt($attempt['attempt_id'])->terminal_reason)->toBe('cart_converted');
});

it('sends nothing to a contact that has no consent at all', function () {
    Mail::fake();
    ['cart_id' => $cartId] = p6cReadyCart(withConsent: false);

    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);

    expect(app(CartReminderDispatcher::class)->dispatch($attempt['attempt_id']))->toBe('suppressed');
    Mail::assertNothingSent();
});

/**
 * A guest cart has NO e-mail column to read. It must never become a candidate, and the
 * ledger must never record a pseudo-address in its place.
 */
it('never treats a guest cart as a reminder candidate', function () {
    Mail::fake();
    $cartId = Cart::cart(status: 'active', inactiveMinutes: 120, userId: null);
    Cart::addItem($cartId, (int) Product::factory()->create()->id);
    Cart::ageActivity($cartId, 120);

    expect(app(CartAbandonmentService::class)->detect())->toBe([$cartId]);
    expect(app(CartReminderService::class)->candidates(null, 1))->toBe([]);

    Mail::assertNothingSent();
});

it('refuses to send inside a database transaction', function () {
    Mail::fake();
    ['cart_id' => $cartId] = p6cReadyCart();

    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);

    DB::beginTransaction();

    try {
        expect(fn () => app(CartReminderDispatcher::class)->dispatch($attempt['attempt_id']))
            ->toThrow(RuntimeException::class, 'outside a database transaction');
    } finally {
        DB::rollBack();
    }

    Mail::assertNothingSent();
});

it('refuses to send through a transport that would record the message', function () {
    Mail::fake();
    ['cart_id' => $cartId] = p6cReadyCart();

    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);

    // The repository fallback: MAIL_MAILER absent resolves to `log`.
    config(['mail.default' => 'log', 'mail.mailers.log' => ['transport' => 'log']]);

    expect(app(CartReminderDispatcher::class)->dispatch($attempt['attempt_id']))->toBe('suppressed');

    Mail::assertNothingSent();
    expect(Cart::attempt($attempt['attempt_id'])->terminal_reason)->toBe('mail_transport_unsafe');
});

it('claims an attempt exactly once so two workers cannot both send', function () {
    Mail::fake();
    ['cart_id' => $cartId] = p6cReadyCart();

    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);

    expect(app(CartReminderDispatcher::class)->dispatch($attempt['attempt_id']))->toBe('sent');

    // A second worker on the same attempt finds it terminal and does nothing.
    expect(app(CartReminderDispatcher::class)->dispatch($attempt['attempt_id']))->toBe('sent');

    expect(Mail::sent(AbandonedCartReminder::class))->toHaveCount(1);
});

it('keeps the attempt identity immutable on a re-enqueue', function () {
    ['cart_id' => $cartId] = p6cReadyCart();
    app(CartAbandonmentService::class)->detect();

    $first = app(CartReminderService::class)->enqueue($cartId, 1);
    $second = app(CartReminderService::class)->enqueue($cartId, 1);

    expect($second['attempt_id'])->toBe($first['attempt_id'])
        ->and($second['created'])->toBeFalse();

    expect((int) Fx::owner()->selectOne(
        'SELECT count(*) AS c FROM cart_reminder_attempts WHERE cart_id = ?', [$cartId],
    )->c)->toBe(1);
});

it('stops offering a step that already has an attempt', function () {
    ['cart_id' => $cartId] = p6cReadyCart();
    app(CartAbandonmentService::class)->detect();

    app(CartReminderService::class)->enqueue($cartId, 1);

    // Step 1 is taken; step 2 is still open.
    expect(app(CartReminderService::class)->candidates(null, 1))->toBe([]);
    expect(collect(app(CartReminderService::class)->candidates(null, 2))->pluck('cart_id')->all())
        ->toBe([$cartId]);
});
