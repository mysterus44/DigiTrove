<?php

declare(strict_types=1);

use App\Jobs\SendCartReminder;
use App\Models\Product;
use App\Services\Cart\CartAbandonmentService;
use App\Services\Cart\CartReminderService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
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
        'crm.cart_reminders.purge_enabled' => true,
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

function p6cOpsCart(): int
{
    $user = Cart::eligibleUser();
    Fx::grantMarketingConsent(Cart::contactFor($user->email));
    $cartId = Cart::cart(status: 'active', inactiveMinutes: 120, userId: (int) $user->id);
    Cart::addItem($cartId, (int) Product::factory()->create()->id);
    Cart::ageActivity($cartId, 120);

    return $cartId;
}

// ── Command output discipline ───────────────────────────────────────────────────

it('reports aggregates only and never a customer identifier', function () {
    Queue::fake();
    $cartId = p6cOpsCart();

    test()->artisan('crm:detect-abandoned-carts')
        ->expectsOutputToContain('transitioned=1')
        ->assertSuccessful();

    test()->artisan('crm:enqueue-cart-reminders')
        ->expectsOutputToContain('queued=1')
        ->assertSuccessful();

    // Nothing that could identify a person: the cart id itself is deliberately absent.
    $source = file_get_contents(app_path('Console/Commands/EnqueueCartReminders.php'))
        .file_get_contents(app_path('Console/Commands/DetectAbandonedCarts.php'))
        .file_get_contents(app_path('Console/Commands/SweepCartReminders.php'))
        .file_get_contents(app_path('Console/Commands/PurgeCartReminders.php'));

    foreach (['->email', 'customer_email', 'capability', 'secret', 'resumeUrl'] as $token) {
        expect($source)->not->toContain($token);
    }
});

// ── Flags are independent and fail closed ───────────────────────────────────────

it('does nothing at all when each flag is off', function () {
    Queue::fake();
    p6cOpsCart();

    config(['crm.cart_reminders.detection_enabled' => false]);
    test()->artisan('crm:detect-abandoned-carts')->expectsOutputToContain('mode=disabled')->assertSuccessful();
    expect(Cart::abandonedCount())->toBe(0);

    config(['crm.cart_reminders.detection_enabled' => true]);
    app(CartAbandonmentService::class)->detect();

    config(['crm.cart_reminders.enqueue_enabled' => false]);
    test()->artisan('crm:enqueue-cart-reminders')->expectsOutputToContain('mode=disabled')->assertSuccessful();
    expect(app(CartReminderService::class)->dueAttemptIds())->toBe([]);

    config(['crm.cart_reminders.send_enabled' => false]);
    test()->artisan('crm:sweep-cart-reminders')->expectsOutputToContain('mode=disabled')->assertSuccessful();
    Queue::assertNothingPushed();

    config(['crm.cart_reminders.purge_enabled' => false]);
    test()->artisan('crm:purge-cart-reminders')->expectsOutputToContain('mode=disabled')->assertSuccessful();
});

/**
 * Enqueuing must NOT imply sending. A deployment can safely start building the ledger
 * long before it is ready to mail anyone.
 */
it('queues a durable attempt without dispatching when sending is off', function () {
    Queue::fake();
    p6cOpsCart();

    app(CartAbandonmentService::class)->detect();
    config(['crm.cart_reminders.send_enabled' => false]);

    test()->artisan('crm:enqueue-cart-reminders')
        ->expectsOutputToContain('queued=1')
        ->expectsOutputToContain('dispatched=0')
        ->assertSuccessful();

    expect(app(CartReminderService::class)->dueAttemptIds())->toHaveCount(1);
    Queue::assertNothingPushed();
});

it('dispatches an ID-only job when sending is on', function () {
    Queue::fake();
    p6cOpsCart();

    app(CartAbandonmentService::class)->detect();

    test()->artisan('crm:enqueue-cart-reminders')
        ->expectsOutputToContain('dispatched=1')
        ->assertSuccessful();

    Queue::assertPushed(SendCartReminder::class, fn (SendCartReminder $job): bool => is_int($job->attemptId));

    // The CONSTRUCTOR is the payload contract. Asserting over public properties cannot
    // work here: PHP flattens trait members into the using class, so Queueable's
    // `$afterCommit`, `$connection` and friends all report SendCartReminder as their
    // declaring class. The constructor signature is the part this gate actually owns.
    $parameters = (new ReflectionMethod(SendCartReminder::class, '__construct'))->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getName())->toBe('attemptId')
        ->and((string) $parameters[0]->getType())->toBe('int');
});

/**
 * The serialised payload is what actually lands in Redis and `failed_jobs`.
 */
it('serialises nothing but the attempt id', function () {
    Queue::fake();
    $cartId = p6cOpsCart();
    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);

    $serialised = serialize(new SendCartReminder($attempt['attempt_id']));

    expect($serialised)->toContain('attemptId')
        ->and($serialised)->not->toContain('@')
        ->and(strtolower($serialised))->not->toContain('secret')
        ->and(strtolower($serialised))->not->toContain('capability')
        ->and(strtolower($serialised))->not->toContain('token');
});

// ── Enqueue idempotence and the step cap ────────────────────────────────────────

it('is idempotent across repeated enqueue runs', function () {
    Queue::fake();
    p6cOpsCart();
    app(CartAbandonmentService::class)->detect();

    test()->artisan('crm:enqueue-cart-reminders')->expectsOutputToContain('queued=1')->assertSuccessful();
    // The step is already taken, so nothing new is created.
    test()->artisan('crm:enqueue-cart-reminders')->expectsOutputToContain('queued=0')->assertSuccessful();

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM cart_reminder_attempts')->c)->toBe(1);
});

it('refuses a step beyond the configured attempt cap', function () {
    Queue::fake();
    p6cOpsCart();
    app(CartAbandonmentService::class)->detect();

    config(['crm.cart_reminders.max_step' => 2]);

    test()->artisan('crm:enqueue-cart-reminders', ['--step' => '2'])
        ->expectsOutputToContain('queued=1')->assertSuccessful();

    // Step 3 exceeds the cap: refused, not clamped to 2.
    test()->artisan('crm:enqueue-cart-reminders', ['--step' => '3'])
        ->expectsOutputToContain('mode=disabled')->assertSuccessful();

    test()->artisan('crm:enqueue-cart-reminders', ['--step' => '0'])
        ->expectsOutputToContain('mode=disabled')->assertSuccessful();

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM cart_reminder_attempts')->c)->toBe(1);
});

// ── Sweep is recovery, never a backfill ─────────────────────────────────────────

it('re-dispatches only still-pending attempts and creates none', function () {
    Queue::fake();
    $cartId = p6cOpsCart();
    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);

    test()->artisan('crm:sweep-cart-reminders')
        ->expectsOutputToContain('dispatched=1')
        ->assertSuccessful();

    Queue::assertPushed(SendCartReminder::class, fn (SendCartReminder $j): bool => $j->attemptId === $attempt['attempt_id']);

    // A terminal attempt is never reopened by the sweep.
    app(CartReminderService::class)->claim($attempt['attempt_id']);
    app(CartReminderService::class)->suppress($attempt['attempt_id'], 'cart_converted');

    test()->artisan('crm:sweep-cart-reminders')
        ->expectsOutputToContain('dispatched=0')
        ->assertSuccessful();

    expect((int) Fx::owner()->selectOne('SELECT count(*) AS c FROM cart_reminder_attempts')->c)->toBe(1);
});

// ── Purge respects the retention frontier ───────────────────────────────────────

it('purges a terminal attempt only once past the retention window', function () {
    $cartId = p6cOpsCart();
    app(CartAbandonmentService::class)->detect();
    $attempt = app(CartReminderService::class)->enqueue($cartId, 1);
    app(CartReminderService::class)->claim($attempt['attempt_id']);
    app(CartReminderService::class)->suppress($attempt['attempt_id'], 'consent_withdrawn');

    // Still inside retention: kept.
    test()->artisan('crm:purge-cart-reminders')->expectsOutputToContain('purged=0')->assertSuccessful();
    expect(Cart::attempt($attempt['attempt_id']))->not->toBeNull();

    Fx::owner()->update(
        "UPDATE cart_reminder_attempts SET updated_at = now() - interval '31 days' WHERE id = ?",
        [$attempt['attempt_id']],
    );

    test()->artisan('crm:purge-cart-reminders')->expectsOutputToContain('purged=1')->assertSuccessful();
    expect(Cart::attempt($attempt['attempt_id']))->toBeNull();

    // Idempotent.
    test()->artisan('crm:purge-cart-reminders')->expectsOutputToContain('purged=0')->assertSuccessful();
});

/**
 * Deleting a live attempt would reset the `(cart, step)` idempotency key and let the
 * same reminder be sent again — the exact thing the ledger exists to prevent.
 */
it('never purges a pending or claimed attempt however old', function () {
    $cartId = p6cOpsCart();
    app(CartAbandonmentService::class)->detect();
    $pending = app(CartReminderService::class)->enqueue($cartId, 1);

    $second = p6cOpsCart();
    app(CartAbandonmentService::class)->detect();
    $claimed = app(CartReminderService::class)->enqueue($second, 1);
    app(CartReminderService::class)->claim($claimed['attempt_id']);

    Fx::owner()->update("UPDATE cart_reminder_attempts SET updated_at = now() - interval '999 days'");

    test()->artisan('crm:purge-cart-reminders')->expectsOutputToContain('purged=0')->assertSuccessful();

    expect(Cart::attempt($pending['attempt_id']))->not->toBeNull()
        ->and(Cart::attempt($claimed['attempt_id']))->not->toBeNull();
});

// ── Scheduler ───────────────────────────────────────────────────────────────────

it('schedules nothing with the repository defaults', function () {
    $routes = file_get_contents(base_path('routes/console.php'));

    expect($routes)->toContain('CartReminderConfig::detectionEnabled()')
        ->toContain("Schedule::command('crm:detect-abandoned-carts')")
        ->toContain("Schedule::command('crm:enqueue-cart-reminders')")
        ->toContain("Schedule::command('crm:sweep-cart-reminders')")
        ->toContain("Schedule::command('crm:purge-cart-reminders')")
        ->toContain('withoutOverlapping()');

    // Boot happens with the shipped defaults (all false), so nothing is registered.
    foreach (['crm:detect-abandoned-carts', 'crm:enqueue-cart-reminders', 'crm:sweep-cart-reminders', 'crm:purge-cart-reminders'] as $command) {
        expect(collect(Schedule::events())->contains(
            fn ($event): bool => str_contains((string) $event->command, $command),
        ))->toBeFalse("{$command} must not be scheduled by default");
    }
});

it('keeps every P6-C flag off in the shipped configuration', function () {
    $config = require base_path('config/crm.php');

    expect($config['cart_reminders']['detection_enabled'])->toBeFalse()
        ->and($config['cart_reminders']['enqueue_enabled'])->toBeFalse()
        ->and($config['cart_reminders']['send_enabled'])->toBeFalse()
        ->and($config['cart_reminders']['purge_enabled'])->toBeFalse()
        // And no cadence is shipped: the operator must choose one.
        ->and($config['cart_reminders']['inactivity_minutes'])->toBeNull()
        ->and($config['cart_reminders']['max_step'])->toBeNull()
        ->and($config['cart_reminders']['cooldown_minutes'])->toBeNull();
});
