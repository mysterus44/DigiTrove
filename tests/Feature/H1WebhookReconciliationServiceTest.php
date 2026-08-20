<?php

declare(strict_types=1);

use App\Services\Payments\WebhookReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| H1 — service et commande de réconciliation
|--------------------------------------------------------------------------
|
| Real PostgreSQL under `digitrove_runtime`. Helpers (h1*) are shared from
| H1WebhookReconciliationSchemaTest.
|
*/

beforeEach(function (): void {
    config([
        'payments.reconciliation.enabled' => true,
        'payments.reconciliation.escalation_minutes' => 15,
        'payments.reconciliation.expiry_hours' => 24,
    ]);
});

function h1Reconcile(bool $execute = true, ?CarbonImmutable $at = null): array
{
    return (new WebhookReconciliationService)->reconcile($execute, $at);
}

/**
 * Insert an event ALREADY aged.
 *
 * ⚠️ `received_at` cannot be rewritten afterwards — the immutability trigger freezes it for
 * the OWNER too, not merely for the runtime role, because it is audit identity. The schema
 * refused a first version of this helper that updated it after the fact. Fixtures bend to
 * the schema; the schema does not bend to fixtures.
 */
function h1AgedEvent(string $status, string $ago, bool $signed = true, array $overrides = []): int
{
    $receivedAt = now()->sub($ago);

    return h1Event($status, $signed, array_merge([
        'received_at' => $receivedAt,
        'created_at' => $receivedAt,
        'updated_at' => $receivedAt,
    ], $overrides));
}

it('closes out both families past the expiry window', function (): void {
    h1AgedEvent('received', '25 hours');
    h1AgedEvent('failed', '25 hours');

    expect(h1Reconcile()['expired'])->toBe(2);

    $rows = DB::table('payment_webhook_events')->orderBy('id')->get();
    expect($rows->pluck('processing_status')->all())
        ->toBe(['unresolved_expired', 'unresolved_expired'])
        // Provenance kept: "never resolved" and "provider unreachable" call for different
        // actions, and collapsing them would destroy the only diagnostic this state has.
        ->and($rows->pluck('expired_from_status')->all())->toBe(['received', 'failed']);
});

it('leaves an event inside the window completely alone', function (): void {
    $recent = h1AgedEvent('received', '23 hours');

    expect(h1Reconcile()['expired'])->toBe(0)
        ->and(DB::table('payment_webhook_events')->find($recent)->processing_status)->toBe('received');
});

it('never touches an event that reached a financial decision', function (string $status): void {
    // `processed` and `ignored` are decisions. However old they are, reconciliation has no
    // business reopening them — and the database would refuse anyway.
    $id = h1AgedEvent($status, '400 days');

    expect(h1Reconcile()['expired'])->toBe(0)
        ->and(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe($status);
})->with(['processed', 'ignored']);

it('never touches an event whose signature was never verified', function (): void {
    // Deliberately rejected, not failed-to-process. Scoping here is what keeps the 000005
    // CHECK intact instead of widened.
    $id = h1AgedEvent('failed', '400 days', signed: false, overrides: [
        'event_type' => null,
        'processing_error_sanitized' => 'Webhook signature verification failed.',
    ]);

    expect(h1Reconcile()['expired'])->toBe(0)
        ->and(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('failed');
});

/*
|--------------------------------------------------------------------------
| Dry-run writes nothing — the default must be safe
|--------------------------------------------------------------------------
*/

it('reports what it would do without writing anything', function (): void {
    $id = h1AgedEvent('received', '25 hours');

    expect(h1Reconcile(execute: false)['expired'])->toBe(1)
        // Counted, not closed.
        ->and(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('received');
});

/*
|--------------------------------------------------------------------------
| The alert is what reaches a human before the record is closed
|--------------------------------------------------------------------------
*/

it('raises a critical alert for events past the threshold but not yet expired', function (): void {
    Log::spy();
    $id = h1AgedEvent('received', '30 minutes');

    expect(h1Reconcile()['escalated'])->toBe(1);

    Log::shouldHaveReceived('critical')->once()->withArgs(function (string $message, array $context) use ($id): bool {
        return str_contains($message, 'unresolved')
            && $context['count'] === 1
            && $context['event_ids'] === [$id]
            // IDs and counts only. A `critical` is forwarded further than most logs and read
            // by people with no business seeing financial identifiers.
            && ! array_key_exists('payload_hash', $context)
            && ! array_key_exists('payment_id', $context);
    });
});

it('does not alert on an event that this same pass already closed out', function (): void {
    // Escalation runs after expiry on purpose: paging someone about something already
    // terminal teaches them to ignore the alert.
    Log::spy();
    $id = h1AgedEvent('received', '25 hours');

    expect(h1Reconcile()['escalated'])->toBe(0)
        ->and(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('unresolved_expired');

    Log::shouldNotHaveReceived('critical');
});

it('stays quiet when nothing is stuck', function (): void {
    Log::spy();
    h1Event('received');

    $result = h1Reconcile();

    expect($result['escalated'])->toBe(0)->and($result['expired'])->toBe(0);
    Log::shouldNotHaveReceived('critical');
});

/*
|--------------------------------------------------------------------------
| Configuration refuses before reading a single row
|--------------------------------------------------------------------------
*/

it('refuses an escalation threshold at or beyond the expiry window', function (int $minutes): void {
    // Individually sensible values, a nonsensical pair: nothing would ever be alerted on
    // before being closed, so the alert would fire on terminal rows, i.e. never.
    config(['payments.reconciliation.escalation_minutes' => $minutes, 'payments.reconciliation.expiry_hours' => 24]);

    expect(fn () => h1Reconcile())->toThrow(RuntimeException::class);
})->with([1440, 1441, 5000]);

it('refuses a bound that is not a whole number rather than coercing it', function (mixed $value): void {
    config(['payments.reconciliation.expiry_hours' => $value]);

    expect(fn () => h1Reconcile())->toThrow(RuntimeException::class);
})->with([[0], ['12abc'], [''], [null], [1.5], [-1], [99999]]);

it('validates before touching a single row', function (): void {
    $id = h1AgedEvent('received', '400 days');
    config(['payments.reconciliation.expiry_hours' => 0]);

    expect(fn () => h1Reconcile())->toThrow(RuntimeException::class)
        // An invalid setting fails the run, never halfway through it.
        ->and(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('received');
});

/*
|--------------------------------------------------------------------------
| The command: dry-run by default, double barrier to mutate
|--------------------------------------------------------------------------
*/

it('is dry-run when no flag is given', function (): void {
    $id = h1AgedEvent('received', '25 hours');

    $this->artisan('payments:reconcile-webhooks')
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('received');
});

it('mutates only with --execute AND the flag enabled', function (): void {
    $id = h1AgedEvent('received', '25 hours');

    $this->artisan('payments:reconcile-webhooks --execute')->assertSuccessful();

    expect(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('unresolved_expired');
});

it('refuses LOUDLY when --execute is given but the flag is off', function (): void {
    // A silent dry-run here would let an operator conclude there was nothing to do.
    config(['payments.reconciliation.enabled' => false]);
    $id = h1AgedEvent('received', '25 hours');

    $this->artisan('payments:reconcile-webhooks --execute')->assertFailed();

    expect(DB::table('payment_webhook_events')->find($id)->processing_status)->toBe('received');
});

it('fails with the reason when the configuration is invalid', function (): void {
    config(['payments.reconciliation.expiry_hours' => 'nonsense']);

    $this->artisan('payments:reconcile-webhooks')->assertFailed();
});
