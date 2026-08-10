<?php

declare(strict_types=1);

use App\Services\Crm\CartAbandonmentService;
use App\Services\Crm\CrmOperationException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\CartReminderFixtures as Cart;

uses(InteractsWithCrmDatabase::class);

beforeEach(function (): void {
    config([
        'crm.foundation_enabled' => true,
        'crm.cart_reminders.detection_enabled' => true,
        'crm.cart_reminders.inactivity_minutes' => 60,
        'crm.cart_reminders.batch_size' => 50,
    ]);
});

it('marks an inactive active cart as abandoned exactly once', function () {
    $cartId = Cart::cart(status: 'active', inactiveMinutes: 120);

    $detected = app(CartAbandonmentService::class)->detect();
    expect($detected)->toBe([$cartId]);

    $cart = Cart::row($cartId);
    expect($cart->status)->toBe('abandoned')
        ->and($cart->abandoned_at)->not->toBeNull();

    // Idempotent: a second pass finds nothing, and abandoned_at is written once.
    $before = $cart->abandoned_at;
    expect(app(CartAbandonmentService::class)->detect())->toBe([]);
    expect(Cart::row($cartId)->abandoned_at)->toBe($before);
});

it('leaves a cart that is still within its inactivity window alone', function () {
    Cart::cart(status: 'active', inactiveMinutes: 30);

    expect(app(CartAbandonmentService::class)->detect())->toBe([]);
});

/**
 * An abandoned cart is one that STOPPED — not one that finished, and not one that timed
 * out. Resurrecting either would be a factual error about the customer.
 */
it('never abandons a converted or expired cart, however inactive', function () {
    $converted = Cart::cart(status: 'converted', inactiveMinutes: 100_000);
    $expired = Cart::cart(status: 'expired', inactiveMinutes: 100_000);
    $abandoned = Cart::cart(status: 'abandoned', inactiveMinutes: 100_000);

    expect(app(CartAbandonmentService::class)->detect())->toBe([]);

    expect(Cart::row($converted)->status)->toBe('converted')
        ->and(Cart::row($expired)->status)->toBe('expired')
        ->and(Cart::row($abandoned)->status)->toBe('abandoned');
});

/**
 * THE reason `last_activity_at` exists. If the transition counted as activity, a cart
 * would look freshly active the instant it was marked abandoned.
 */
it('does not treat its own transition as cart activity', function () {
    $cartId = Cart::cart(status: 'active', inactiveMinutes: 120);
    $activityBefore = Cart::row($cartId)->last_activity_at;

    app(CartAbandonmentService::class)->detect();

    expect(Cart::row($cartId)->last_activity_at)->toBe($activityBefore);
});

it('bounds the batch and pages deterministically', function () {
    $ids = [];

    for ($i = 0; $i < 5; $i++) {
        $ids[] = Cart::cart(status: 'active', inactiveMinutes: 120);
    }

    config(['crm.cart_reminders.batch_size' => 2]);

    $first = app(CartAbandonmentService::class)->detect();
    expect($first)->toHaveCount(2);

    $second = app(CartAbandonmentService::class)->detect();
    expect($second)->toHaveCount(2)
        ->and(array_intersect($first, $second))->toBe([]);

    $third = app(CartAbandonmentService::class)->detect();
    expect($third)->toHaveCount(1);

    expect(app(CartAbandonmentService::class)->detect())->toBe([]);
    expect(array_merge($first, $second, $third))->toEqualCanonicalizing($ids);
});

it('refuses to run when detection is disabled or the window is unconfigured', function () {
    Cart::cart(status: 'active', inactiveMinutes: 120);

    config(['crm.cart_reminders.detection_enabled' => false]);
    expect(fn () => app(CartAbandonmentService::class)->detect())->toThrow(RuntimeException::class);

    // Enabled but with no operator-chosen window: refuse rather than invent a cadence.
    config(['crm.cart_reminders.detection_enabled' => true, 'crm.cart_reminders.inactivity_minutes' => null]);
    expect(fn () => app(CartAbandonmentService::class)->detect())
        ->toThrow(RuntimeException::class, 'must be configured explicitly');

    // Out of bounds is refused too.
    config(['crm.cart_reminders.inactivity_minutes' => 4]);
    expect(fn () => app(CartAbandonmentService::class)->detect())->toThrow(RuntimeException::class);

    config(['crm.cart_reminders.inactivity_minutes' => 525601]);
    expect(fn () => app(CartAbandonmentService::class)->detect())->toThrow(RuntimeException::class);

    // Nothing transitioned through any of those refusals.
    expect(Cart::abandonedCount())->toBe(0);
});

it('refuses the authority outside the runtime identity', function () {
    config(['crm.foundation_enabled' => false]);

    expect(fn () => app(CartAbandonmentService::class)->detect())->toThrow(RuntimeException::class);
});

it('never reports a raw database message', function () {
    config(['crm.cart_reminders.inactivity_minutes' => 60]);
    Cart::cart(status: 'active', inactiveMinutes: 120);

    // A CRM-level failure, never a PostgreSQL 23514.
    config(['crm.foundation_enabled' => true]);
    DB::beginTransaction();

    try {
        expect(fn () => app(CartAbandonmentService::class)->detect())
            ->toThrow(CrmOperationException::class);
    } finally {
        DB::rollBack();
    }
});
