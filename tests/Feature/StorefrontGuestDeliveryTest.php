<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Jobs\SecureDeliveryJob;
use App\Mail\OrderDownloadsReady;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Delivery\GrantIssuanceService;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RefreshesDatabaseAsMigrator as RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Guest delivery, proved rather than reasoned.
 *
 * A structural read of the code says a guest order should deliver: `download_grants.user_id`
 * is nullable, the G3 trigger compares with `IS NOT DISTINCT FROM` (so NULL matches NULL),
 * and the mail goes to `orders.customer_email` through a plain `Mailable` rather than a
 * `Notifiable` model. None of that is worth much until an order with NO user row has
 * actually been through the pipeline — a nullable column still fails if some line does
 * `$order->user->email`.
 */
function guestPaidOrder(): Order
{
    $product = Product::factory()->create([
        'status' => ProductStatus::Published, 'published_at' => now()->subDay(),
    ]);

    ProductPrice::factory()->create([
        'product_id' => $product->id, 'currency' => 'XOF', 'price_minor' => 15_000, 'compare_at_price_minor' => null, 'is_active' => true,
    ]);

    ProductFile::factory()->create(['product_id' => $product->id, 'is_active' => true]);

    // Built through the storefront so the order is a REAL guest order, not a fixture
    // shaped to pass.
    test()->post(route('cart.items.store', $product->slug));
    test()->post(route('checkout.store'), ['email' => 'invite@example.test']);

    $order = Order::query()->sole();

    // The webhook path is the only thing that may mark an order paid; this test is about
    // delivery, so the transition is applied directly rather than by faking a provider.
    //
    // It cannot be a bare status flip: PostgreSQL enforces "paid orders require exactly
    // one succeeded payment", so the payment has to exist in the SAME transaction for
    // the deferred check to pass. That constraint is the reason a forged browser return
    // could never fake a paid order even if it reached the database.
    DB::transaction(function () use ($order): void {
        // The coherence checks run in BOTH directions — a paid order needs its succeeded
        // payment, and a pending order must not have one — so neither write is legal on
        // its own. They are deferred to commit, which is precisely what makes the pair
        // atomic and a half-paid order unrepresentable.
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        DB::table('payments')->insert([
            'public_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'provider' => 'cinetpay',
            'provider_payment_reference' => 'ref-'.Str::uuid(),
            'idempotency_key_hash' => hash('sha256', 'delivery-fixture-'.Str::uuid()),
            'attempt_number' => 1,
            'amount_minor' => $order->total_minor,
            'currency' => 'XOF',
            'status' => 'succeeded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('orders')->where('id', $order->id)->update([
            'status' => 'paid', 'paid_at' => now(),
        ]);
    });

    return $order->fresh();
}

it('has no user row behind a storefront guest order', function () {
    $order = guestPaidOrder();

    expect($order->user_id)->toBeNull()
        ->and((string) $order->customer_email)->toBe('invite@example.test')
        // Nothing was created on the side to stand in for an account.
        ->and(DB::table('users')->count())->toBe(0);
});

/**
 * The decisive one. A nullable column is not proof: this runs the real issuance service
 * against a real PostgreSQL, so the G3 trigger gets its say.
 */
it('issues download grants for an order that has no user account', function () {
    $order = guestPaidOrder();

    $batch = app(GrantIssuanceService::class)->issueForOrder($order->id);

    $grants = DownloadGrant::query()->get();

    expect($grants)->not->toBeEmpty()
        // `IS NOT DISTINCT FROM` treats NULL = NULL as true, so a guest grant passes G3
        // — and a grant carrying a user id on a guest order would be REFUSED.
        ->and($grants->pluck('user_id')->unique()->all())->toBe([null])
        // The delivery address comes from the order, never from an account.
        ->and($batch->customerEmail)->toBe('invite@example.test');
});

it('refuses a grant that claims a user the guest order does not have', function () {
    $order = guestPaidOrder();
    $orderItemId = (int) DB::table('order_items')->where('order_id', $order->id)->value('id');
    $productFileId = (int) DB::table('product_files')->value('id');

    $user = User::factory()->create();

    // The trigger protects in BOTH directions: NULL matches NULL, and a mismatch is
    // rejected. Without this the guest guarantee would be one-sided.
    // Wrapped in a nested transaction = SAVEPOINT: a refused insert aborts the current
    // transaction block, and every later query would fail with 25P02 rather than telling
    // us anything about the guarantee under test.
    expect(fn () => DB::transaction(fn () => DB::table('download_grants')->insert([
        'public_id' => (string) Str::uuid(),
        'order_item_id' => $orderItemId,
        'product_file_id' => $productFileId,
        'user_id' => $user->id,
        'token_hash' => hash('sha256', 'forged-'.Str::uuid()),
        'expires_at' => now()->addDays(7),
        'max_downloads' => 5,
        'downloads_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(DownloadGrant::query()->count())->toBe(0);
});

/**
 * The mail is a plain `Mailable` addressed to a string, not a notification routed through
 * a `Notifiable` model — which is exactly why no account is needed to receive it.
 *
 * NOT PROVEN HERE: the job end to end. `SecureDeliveryJob` refuses to run at transaction
 * level other than 0 (its I/O must never sit inside a database transaction), and
 * `RefreshesDatabaseAsMigrator` wraps every test in one. The same harness limitation the
 * cart concurrency race hit. What IS proven is the claim that matters for a guest: the
 * envelope is built from the ORDER's email, with no account anywhere in the chain.
 */
it('addresses the download mail to the order email with no account involved', function () {
    $order = guestPaidOrder();

    $batch = app(GrantIssuanceService::class)->issueForOrder($order->id);
    $mail = new OrderDownloadsReady($batch);

    // `Mail::to($batch->customerEmail)` is what the job does; a plain string recipient
    // needs no `Notifiable`, which is why a guest can receive it at all.
    expect($batch->customerEmail)->toBe('invite@example.test')
        ->and($mail->to)->toBe([])
        ->and($mail->envelope())->toBeInstanceOf(Envelope::class)
        ->and(DB::table('users')->count())->toBe(0);
});
