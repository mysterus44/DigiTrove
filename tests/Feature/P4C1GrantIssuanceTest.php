<?php

declare(strict_types=1);

use App\Enums\GrantRevocationReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\Refund;
use App\Models\User;
use App\Services\Delivery\GrantIssuanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPaymentsDatabase;

uses(InteractsWithPaymentsDatabase::class);

/*
|--------------------------------------------------------------------------
| P4-C1 — Grant issuance (D-035)
|--------------------------------------------------------------------------
|
| Real PostgreSQL. The purchase snapshot is the sole bundle authority; the live
| pivot is never read. Tokens are CSPRNG, only their SHA-256 digest is stored.
|
*/

function p4cConfig(): void
{
    config(['delivery' => [
        'enabled' => true,
        'grant' => ['ttl_minutes' => 10080, 'max_downloads' => 5],
        'job' => ['unique_seconds' => 3600],
        'download_base_url' => 'https://dl.example.com/d',
        'require_https' => true,
    ]]);
}

function p4cProduct(string $status = 'published'): Product
{
    return DB::transaction(fn () => Product::factory()->create(['status' => $status]));
}

function p4cFile(Product $product, bool $active = true, string $name = 'file.zip'): ProductFile
{
    return DB::transaction(fn () => ProductFile::factory()->create([
        'product_id' => $product->id,
        'is_active' => $active,
        'original_name' => $name,
    ]));
}

/**
 * A paid order for one line, with its single succeeded payment. Returns the
 * order id and the order_item id.
 *
 * @return array{order: Order, order_item_id: int, user: ?User}
 */
function p4cDeliverableOrder(Product $product, string $typeSnapshot = 'ebook', ?User $user = null, string $status = 'paid'): array
{
    return DB::transaction(function () use ($product, $typeSnapshot, $user, $status): array {
        $order = Order::factory()->create([
            'status' => $status,
            'total_minor' => 15_000,
            'subtotal_minor' => 15_000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'currency' => 'XOF',
            'user_id' => $user?->id,
            'visitor_id' => null,
            'coupon_id' => null,
            'placed_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'paid_at' => now(),
        ]);

        $orderItemId = DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'purchased_product_id' => $product->id,
            'product_name_snapshot' => 'Prod',
            'product_slug_snapshot' => 'prod',
            'product_type_snapshot' => $typeSnapshot,
            'unit_price_minor' => 15_000,
            'quantity' => 1,
            'line_subtotal_minor' => 15_000,
            'line_discount_minor' => 0,
            'line_total_minor' => 15_000,
            'currency' => 'XOF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A paid order needs its single succeeded payment (deferred consistency).
        Payment::factory()->create([
            'public_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'provider' => 'cinetpay',
            'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
            'attempt_number' => 1,
            'amount_minor' => 15_000,
            'currency' => 'XOF',
            'status' => PaymentStatus::Succeeded,
            'initiated_at' => now(),
            'processing_at' => now(),
            'succeeded_at' => now(),
        ]);

        return ['order' => $order, 'order_item_id' => $orderItemId, 'user' => $user];
    });
}

function p4cIssuer(): GrantIssuanceService
{
    return new GrantIssuanceService;
}

beforeEach(function (): void {
    p4cConfig();
});

it('issues a grant for each active file of a simple product', function (): void {
    $product = p4cProduct();
    p4cFile($product, name: 'a.zip');
    p4cFile($product, name: 'b.zip');
    ['order' => $order, 'order_item_id' => $itemId] = p4cDeliverableOrder($product);

    $batch = p4cIssuer()->issueForOrder($order->id);

    expect($batch->grants)->toHaveCount(2)
        ->and(DownloadGrant::query()->where('order_item_id', $itemId)->whereNull('revoked_at')->count())->toBe(2);
});

it('excludes an inactive product file', function (): void {
    $product = p4cProduct();
    p4cFile($product, active: true, name: 'active.zip');
    p4cFile($product, active: false, name: 'inactive.zip');
    ['order' => $order] = p4cDeliverableOrder($product);

    $batch = p4cIssuer()->issueForOrder($order->id);

    expect($batch->grants)->toHaveCount(1)
        ->and($batch->grants[0]->fileName)->toBe('active.zip');
});

it('stores only the SHA-256 of a CSPRNG token, never the raw token', function (): void {
    $product = p4cProduct();
    p4cFile($product);
    ['order' => $order] = p4cDeliverableOrder($product);

    $batch = p4cIssuer()->issueForOrder($order->id);
    $raw = $batch->grants[0]->rawToken;

    $grant = DownloadGrant::query()->firstOrFail();
    expect($grant->getAttributes()['token_hash'])->toBe(hash('sha256', $raw))
        ->and($grant->getAttributes()['token_hash'])->toMatch('/\A[0-9a-f]{64}\z/')
        // the raw token is nowhere in the row
        ->and(json_encode($grant->getAttributes()))->not->toContain($raw)
        ->and(strlen($raw))->toBeGreaterThanOrEqual(43); // 32 bytes base64url
});

it('issues a distinct token per grant', function (): void {
    $product = p4cProduct();
    p4cFile($product, name: 'a.zip');
    p4cFile($product, name: 'b.zip');
    ['order' => $order] = p4cDeliverableOrder($product);

    $batch = p4cIssuer()->issueForOrder($order->id);

    $tokens = array_map(fn ($g) => $g->rawToken, $batch->grants);
    expect(array_unique($tokens))->toHaveCount(2);
});

it('retries only an exact token-hash collision and persists the fresh digest', function (): void {
    $collidingToken = str_repeat('A', 43);
    $freshToken = str_repeat('B', 43);

    $firstProduct = p4cProduct();
    p4cFile($firstProduct);
    ['order' => $firstOrder] = p4cDeliverableOrder($firstProduct);
    (new GrantIssuanceService(static fn (): string => $collidingToken))
        ->issueForOrder($firstOrder->id);

    $secondProduct = p4cProduct();
    p4cFile($secondProduct);
    ['order' => $secondOrder] = p4cDeliverableOrder($secondProduct);
    $tokens = [$collidingToken, $freshToken];
    $issuer = new GrantIssuanceService(static function () use (&$tokens): string {
        return array_shift($tokens);
    });

    $batch = $issuer->issueForOrder($secondOrder->id);

    expect($batch->grants)->toHaveCount(1)
        ->and($batch->grants[0]->rawToken)->toBe($freshToken)
        ->and(DownloadGrant::query()->where('token_hash', hash('sha256', $freshToken))->count())->toBe(1)
        ->and(DownloadGrant::query()->count())->toBe(2);
});

it('bounds repeated token-hash collisions and exposes no SQL detail', function (): void {
    $collidingToken = str_repeat('C', 43);

    $firstProduct = p4cProduct();
    p4cFile($firstProduct);
    ['order' => $firstOrder] = p4cDeliverableOrder($firstProduct);
    (new GrantIssuanceService(static fn (): string => $collidingToken))
        ->issueForOrder($firstOrder->id);

    $secondProduct = p4cProduct();
    p4cFile($secondProduct);
    ['order' => $secondOrder, 'order_item_id' => $secondItemId] = p4cDeliverableOrder($secondProduct);

    $failure = null;
    try {
        (new GrantIssuanceService(static fn (): string => $collidingToken))
            ->issueForOrder($secondOrder->id);
    } catch (RuntimeException $exception) {
        $failure = $exception;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->getMessage())->toBe('A secure download credential could not be issued.')
        ->and($failure->getMessage())->not->toContain('23505')
        ->and($failure->getMessage())->not->toContain('download_grants_token_hash_unique')
        ->and(DownloadGrant::query()->where('order_item_id', $secondItemId)->count())->toBe(0);
});

it('sets an explicit TTL and quota with no commercial default', function (): void {
    $product = p4cProduct();
    p4cFile($product);
    ['order' => $order] = p4cDeliverableOrder($product);

    p4cIssuer()->issueForOrder($order->id);

    $grant = DownloadGrant::query()->firstOrFail();
    expect($grant->max_downloads)->toBe(5)
        ->and($grant->downloads_count)->toBe(0)
        ->and($grant->expires_at->greaterThan(now()))->toBeTrue();
});

it('leaves the grant user null for a guest order', function (): void {
    $product = p4cProduct();
    p4cFile($product);
    ['order' => $order] = p4cDeliverableOrder($product, user: null);

    p4cIssuer()->issueForOrder($order->id);

    expect(DownloadGrant::query()->firstOrFail()->user_id)->toBeNull();
});

it('sets the grant user to the buyer for a registered order', function (): void {
    $user = DB::transaction(fn () => User::factory()->create());
    $product = p4cProduct();
    p4cFile($product);
    ['order' => $order] = p4cDeliverableOrder($product, user: $user);

    p4cIssuer()->issueForOrder($order->id);

    expect(DownloadGrant::query()->firstOrFail()->user_id)->toBe($user->id);
});

it('refuses a pending order', function (): void {
    $product = p4cProduct();
    p4cFile($product);
    // A pending order with no payment (free-shaped) — issuance must refuse.
    $order = DB::transaction(function () use ($product) {
        $o = Order::factory()->create([
            'status' => OrderStatus::Pending, 'total_minor' => 0, 'subtotal_minor' => 0,
            'discount_minor' => 0, 'tax_minor' => 0, 'currency' => 'XOF',
            'placed_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $o->id, 'product_id' => $product->id, 'purchased_product_id' => $product->id, 'product_name_snapshot' => 'p',
            'product_slug_snapshot' => 'p', 'product_type_snapshot' => 'ebook', 'unit_price_minor' => 0,
            'quantity' => 1, 'line_subtotal_minor' => 0, 'line_discount_minor' => 0, 'line_total_minor' => 0,
            'currency' => 'XOF', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $o;
    });

    expect(fn () => p4cIssuer()->issueForOrder($order->id))->toThrow(RuntimeException::class);
    expect(DownloadGrant::query()->count())->toBe(0);
});

it('refuses a fully refunded order', function (): void {
    $product = p4cProduct();
    p4cFile($product);
    ['order' => $order] = p4cDeliverableOrder($product);
    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

    DB::transaction(function () use ($order, $payment): void {
        Refund::factory()->forPayment($payment)->succeeded()->create([
            'amount_minor' => $payment->amount_minor,
        ]);
        $order->forceFill(['status' => OrderStatus::Refunded->value])->save();
    });

    expect(fn () => p4cIssuer()->issueForOrder($order->id))
        ->toThrow(RuntimeException::class, 'The order is not deliverable.');
    expect(DownloadGrant::query()->count())->toBe(0);
});

it('delivers a bundle strictly from the purchase snapshot, not the live pivot', function (): void {
    $parent = p4cProduct();
    $childInSnapshot = p4cProduct();
    $childAddedLater = p4cProduct(); // present in the LIVE pivot only, never purchased
    p4cFile($parent, name: 'parent.zip');
    p4cFile($childInSnapshot, name: 'child.zip');
    p4cFile($childAddedLater, name: 'sneaky.zip');

    // The LIVE bundle pivot contains BOTH children.
    DB::table('product_bundles')->insert([
        ['bundle_id' => $parent->id, 'child_product_id' => $childInSnapshot->id, 'position' => 0],
        ['bundle_id' => $parent->id, 'child_product_id' => $childAddedLater->id, 'position' => 1],
    ]);

    ['order' => $order, 'order_item_id' => $itemId] = p4cDeliverableOrder($parent, typeSnapshot: 'bundle');

    // The purchase SNAPSHOT contains ONLY the child that was actually bought.
    DB::table('order_item_bundle_components')->insert([
        'order_item_id' => $itemId,
        'child_product_id' => $childInSnapshot->id,
        'child_product_name_snapshot' => 'child',
        'child_product_slug_snapshot' => 'child',
        'created_at' => now(),
    ]);
    // The sneaky child is in the live pivot but NOT the snapshot: it must not be delivered.

    $batch = p4cIssuer()->issueForOrder($order->id);

    $names = array_map(fn ($g) => $g->fileName, $batch->grants);
    sort($names);
    expect($names)->toBe(['child.zip', 'parent.zip']);
});

it('reissues exactly the historical pairs and never upgrades to a new file', function (): void {
    $product = p4cProduct();
    p4cFile($product, name: 'original.zip');
    ['order' => $order, 'order_item_id' => $itemId] = p4cDeliverableOrder($product);

    $first = p4cIssuer()->issueForOrder($order->id);
    expect($first->grants)->toHaveCount(1);

    // A new file is added to the product AFTER the first issuance.
    p4cFile($product, name: 'added-later.zip');

    $second = p4cIssuer()->issueForOrder($order->id);

    // Same single historical pair reissued; the new file is NOT delivered.
    expect($second->grants)->toHaveCount(1)
        ->and($second->grants[0]->fileName)->toBe('original.zip')
        // the previous active grant was revoked (uncertain reissue), a fresh one created
        ->and(DownloadGrant::query()->where('order_item_id', $itemId)->whereNull('revoked_at')->count())->toBe(1)
        ->and(DownloadGrant::query()->where('order_item_id', $itemId)->whereNotNull('revoked_at')->where('revoked_reason_code', GrantRevocationReason::DeliveryUncertainReissue->value)->count())->toBe(1);
});

it('leaves all deferred constraints valid after issuing a complete batch', function (): void {
    $product = p4cProduct();
    p4cFile($product);
    ['order' => $order] = p4cDeliverableOrder($product);

    p4cIssuer()->issueForOrder($order->id);

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');

    expect(DownloadGrant::query()->whereNull('revoked_at')->count())->toBe(1);
});
