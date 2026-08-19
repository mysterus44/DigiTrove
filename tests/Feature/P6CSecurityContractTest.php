<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;
use Tests\Support\SourceScanner as Scanner;

/**
 * P6-C security contract. Scans ONLY the files this gate owns, as CODE (comments
 * stripped), so a file may keep documenting exactly what it refuses to do.
 */

/** @return array{php: list<string>, blade: list<string>} */
function p6cOwnedFiles(): array
{
    $root = dirname(__DIR__, 2);

    return [
        'php' => [
            $root.'/app/Console/Commands/DetectAbandonedCarts.php',
            $root.'/app/Console/Commands/EnqueueCartReminders.php',
            $root.'/app/Console/Commands/PurgeCartReminders.php',
            $root.'/app/Console/Commands/SweepCartReminders.php',
            $root.'/app/Http/Controllers/CartResumeController.php',
            $root.'/app/Jobs/SendCartReminder.php',
            $root.'/app/Mail/AbandonedCartReminder.php',
            $root.'/app/Services/Cart/CartAbandonmentService.php',
            $root.'/app/Services/Cart/CartReminderDispatcher.php',
            $root.'/app/Services/Cart/CartReminderEligibility.php',
            $root.'/app/Services/Cart/CartReminderService.php',
            $root.'/app/Support/CartReminderConfig.php',
            $root.'/app/Support/MailTransportGuard.php',
        ],
        'blade' => [
            $root.'/resources/views/carts/resume.blade.php',
            $root.'/resources/views/carts/resumed.blade.php',
            $root.'/resources/views/carts/resume-unavailable.blade.php',
            $root.'/resources/views/mail/abandoned-cart-reminder.blade.php',
        ],
    ];
}

it('scans exactly the files P6-C owns and fails closed if one disappears', function () {
    $files = p6cOwnedFiles();

    expect($files['php'])->toHaveCount(13)
        ->and($files['blade'])->toHaveCount(4);

    foreach ([...$files['php'], ...$files['blade']] as $file) {
        expect(is_file($file))->toBeTrue("P6-C file is missing: {$file}");
    }
});

it('adds exactly one migration and never touches a historical one', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(49)
        ->and(glob($root.'/database/migrations/2026_07_14_000028*.php'))->toHaveCount(1)
        // P6-D1.1 has not started.
        ->and(glob($root.'/database/migrations/2026_07_14_000034*.php') ?: [])->toBe([]);
});

it('never reaches a crm_ or commerce table directly from the runtime layer', function () {
    $forbidden = [
        'DB::table(', '::query()', 'FROM crm_', 'INSERT INTO crm_', 'UPDATE crm_',
        'DELETE FROM crm_', 'pgsql_migration', 'digitrove_crm_executor',
    ];

    foreach (p6cOwnedFiles()['php'] as $file) {
        // The eligibility service legitimately reads Commerce through Eloquent, which is
        // the runtime's own domain; the CRM boundary is what must stay authority-only.
        $skip = str_contains($file, 'CartReminderEligibility.php')
            ? ['DB::table(', '::query()']
            : [];

        expect(Scanner::violations(Scanner::phpCode($file), array_diff($forbidden, $skip)))
            ->toBe([], "forbidden data access in {$file}");
    }
});

it('drives the ledger only through the bounded EXECUTE-only authorities', function () {
    $service = Scanner::phpCode(app_path('Services/Cart/CartReminderService.php'));

    foreach ([
        'public.list_cart_reminder_candidates(', 'public.enqueue_cart_reminder(',
        'public.list_due_cart_reminders(', 'public.claim_cart_reminder(',
        'public.attach_cart_reminder_secret(', 'public.complete_cart_reminder(',
        'public.suppress_cart_reminder(', 'public.fail_cart_reminder(',
        'public.resolve_cart_reminder_by_secret(', 'public.purge_cart_reminders(',
    ] as $authority) {
        expect($service)->toContain($authority);
    }

    expect(Scanner::phpCode(app_path('Services/Cart/CartAbandonmentService.php')))
        ->toContain('public.mark_abandoned_carts(');
});

it('keeps operational pagination keyset-bounded with no OFFSET or fuzzy matching', function () {
    foreach (p6cOwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), ['OFFSET', 'ILIKE', ' LIKE ', 'similar to']))
            ->toBe([], "forbidden pagination or matching construct in {$file}");
    }
});

/**
 * Abandonment must be decided on Commerce facts. Analytics is explicitly non-authoritative
 * (D-037), and a visitor cannot be turned into an e-mail.
 */
it('never uses analytics or visitor stitching as an abandonment source', function () {
    foreach (p6cOwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), [
            'analytics_sessions', 'daily_funnel_stats', 'daily_sales_stats',
            'visitor_id', 'AnalyticsReader', 'events',
        ]))->toBe([], "non-authoritative abandonment source in {$file}");
    }
});

it('carries an id and nothing else through the queue', function () {
    $job = Scanner::phpCode(app_path('Jobs/SendCartReminder.php'));

    expect($job)->toContain('public readonly int $attemptId')
        ->and($job)->toContain('ShouldBeUnique')
        ->and($job)->toContain('ShouldQueue');

    foreach (['email', 'capability', 'secret', 'resumeUrl', 'getMessage', 'cart_public_id'] as $token) {
        expect(Scanner::violations($job, [$token]))->toBe([], "queue payload leak: {$token}");
    }
});

/**
 * The capability is the whole confidentiality story: it is a live key to a cart.
 */
it('never logs, dumps or persists the raw capability', function () {
    foreach (p6cOwnedFiles()['php'] as $file) {
        // `ray(` is deliberately NOT in this list: it is a substring of `in_array(`, so
        // the naive token flags every legitimate allowlist check. The debug helper is
        // matched by its real call shapes instead.
        expect(Scanner::violations(Scanner::phpCode($file), [
            'Log::', 'logger(', 'dd(', 'dump(', 'var_dump', 'ray()->', 'Ray::', 'report(',
            'getTraceAsString',
        ]))->toBe([], "capability could leak from {$file}");
    }

    // Only the digest is ever written; the raw value never reaches a column.
    $ledger = Scanner::phpCode(app_path('Services/Cart/CartReminderService.php'));
    expect($ledger)->toContain('secretHash')
        ->and($ledger)->not->toContain('$rawSecret');
});

it('never places the capability in a query string or browser storage', function () {
    $view = Scanner::bladeMarkup(resource_path('views/carts/resume.blade.php'));

    // It travels in the FRAGMENT, then in a POST body — never a URL parameter.
    expect($view)->toContain('location.hash')
        ->and($view)->toContain('history.replaceState')
        ->and($view)->toContain("method: 'POST'");

    foreach (['localStorage', 'sessionStorage', 'document.cookie', 'console.', '?c=', '&c='] as $forbidden) {
        expect($view)->not->toContain($forbidden);
    }
});

it('loads no analytics or third-party resource on the resume surface', function () {
    foreach (p6cOwnedFiles()['blade'] as $file) {
        expect(Scanner::violations(Scanner::bladeMarkup($file), [
            'http://', 'googletagmanager', 'analytics', 'gtag', 'cdn.', 'unpkg', 'jsdelivr',
        ]))->toBe([], "third-party or analytics resource in {$file}");
    }

    $controller = Scanner::phpCode(app_path('Http/Controllers/CartResumeController.php'));
    expect($controller)->toContain("default-src 'none'")
        ->and($controller)->not->toContain('unsafe-inline')
        ->and($controller)->not->toContain('unsafe-eval');
});

it('sends through no invented provider and integrates with nothing external', function () {
    foreach (p6cOwnedFiles()['php'] as $file) {
        expect(Scanner::violations(Scanner::phpCode($file), [
            'Http::', 'GuzzleHttp', 'curl_', 'api.brevo', 'api.mailgun', 'api.postmark',
            'api.resend', 'sendgrid',
        ]))->toBe([], "invented provider surface in {$file}");
    }
});

it('keeps provider I/O outside database transactions', function () {
    $dispatcher = Scanner::phpCode(app_path('Services/Cart/CartReminderDispatcher.php'));

    // Structural, not a convention the caller has to remember.
    expect($dispatcher)->toContain('assertOutsideTransaction')
        ->and($dispatcher)->toContain('DB::transactionLevel() !== 0')
        ->and($dispatcher)->not->toContain('DB::transaction(')
        ->and($dispatcher)->not->toContain('beginTransaction');
});

it('refuses an unsafe mail transport before any capability exists', function () {
    $config = Scanner::phpCode(app_path('Support/CartReminderConfig.php'));

    // The send gate carries the transport check, so a link cannot be minted for a
    // transport that would write it to disk.
    expect($config)->toContain('MailTransportGuard::assertSafe()');

    $guard = Scanner::phpCode(app_path('Support/MailTransportGuard.php'));
    expect($guard)->toContain("'log'")
        ->and($guard)->toContain("'array'")
        ->and($guard)->toContain('COMPOSITE_TRANSPORTS');
});

it('keeps every operational flag and every schedule off by default', function () {
    $config = require base_path('config/crm.php');

    foreach (['detection_enabled', 'enqueue_enabled', 'send_enabled', 'purge_enabled'] as $flag) {
        expect($config['cart_reminders'][$flag])->toBeFalse("{$flag} must ship off");
    }

    // No marketing cadence is shipped: the operator must choose one, or P6-C refuses.
    foreach (['inactivity_minutes', 'max_step', 'cooldown_minutes', 'capability_ttl_minutes', 'retention_days'] as $setting) {
        expect($config['cart_reminders'][$setting])->toBeNull("{$setting} must not ship a value");
    }

    foreach (['crm:detect-abandoned-carts', 'crm:enqueue-cart-reminders', 'crm:sweep-cart-reminders', 'crm:purge-cart-reminders'] as $command) {
        expect(collect(Schedule::events())->contains(
            fn ($event): bool => str_contains((string) $event->command, $command),
        ))->toBeFalse("{$command} must not be scheduled by default");
    }
});

it('adds no storefront: no generic cart controller, model surface or API', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/app/Http/Controllers/CartController.php') ?: [])->toBe([])
        ->and(glob($root.'/app/Http/Controllers/Api/Cart*.php') ?: [])->toBe([])
        ->and(is_dir($root.'/app/Http/Controllers/Cart'))->toBeFalse();

    // The only cart-bearing controller is the P6-C resume surface.
    $controllers = glob($root.'/app/Http/Controllers/*Cart*.php') ?: [];
    expect(array_map('basename', $controllers))->toBe(['CartResumeController.php']);

    // And it can neither mutate a cart nor check out.
    $resume = Scanner::phpCode($root.'/app/Http/Controllers/CartResumeController.php');
    foreach (['CartItem', 'OrderService', 'checkout', '->save()', '->update(', '->delete('] as $forbidden) {
        expect($resume)->not->toContain($forbidden);
    }
});

it('emits aggregate-only command output with no identifier', function () {
    foreach ([
        'DetectAbandonedCarts', 'EnqueueCartReminders', 'PurgeCartReminders', 'SweepCartReminders',
    ] as $command) {
        $source = Scanner::phpCode(app_path("Console/Commands/{$command}.php"));

        foreach (['->email', 'public_id', 'capability', 'secret', 'cart_id='] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
});
