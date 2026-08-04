<?php

declare(strict_types=1);

namespace App\Services\Checkout;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Pricing\PricedQuote;
use App\Services\Pricing\PricingException;
use App\Services\Pricing\PricingRefusalReason;
use App\Services\Pricing\PricingService;
use App\Support\Money;
use App\Support\OrderNumberGenerator;
use App\Support\PostgresConstraintViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Turns a locked cart into a `pending` Order, atomically (P3-D2, D-031).
 *
 * One transaction creates the Order, its OrderItems, the exhaustive bundle
 * snapshots, and converts the Cart. Nothing else: no payment, no coupon
 * redemption, no domain event, no download grant.
 *
 * The quote is ALWAYS recomputed server side by PricingService inside this
 * transaction, after the locks. No amount, snapshot or eligibility supplied by
 * the caller is ever trusted — the signature does not even expose them.
 */
final class OrderService
{
    private const ORDER_NUMBER_ATTEMPTS = 3;

    private const IDEMPOTENCY_KEY_PATTERN = '/\A[A-Za-z0-9._-]{32,255}\z/';

    private const MAX_NEW_CUSTOMER_EMAIL_LENGTH = 254;

    private const MAX_HISTORICAL_CUSTOMER_EMAIL_LENGTH = 320;

    /** A year of minutes: far beyond any sane cart, still safe for Carbon. */
    private const MAX_PENDING_TTL_MINUTES = 525_600;

    public function __construct(
        private readonly PricingService $pricing,
        private readonly OrderNumberGenerator $orderNumbers,
    ) {}

    /**
     * @param  string  $idempotencyKey  raw, opaque, never stored nor logged
     *
     * @throws CheckoutException
     */
    public function checkout(
        User|Visitor $actor,
        string $cartPublicId,
        string $currency,
        string $idempotencyKey,
        ?string $guestEmail = null,
        ?CarbonImmutable $at = null,
    ): Order {
        try {
            Money::assertValidCurrency($currency);
        } catch (InvalidArgumentException) {
            throw CheckoutException::of(CheckoutRefusalReason::InvalidCurrency, 'Unsupported currency.');
        }

        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $idempotencyKey) !== 1) {
            throw CheckoutException::of(
                CheckoutRefusalReason::InvalidIdempotencyKey,
                'The idempotency key must be an opaque high-entropy token.',
            );
        }

        // Only the digest ever leaves this method.
        $digest = hash('sha256', $idempotencyKey);
        $now = $at ?? CarbonImmutable::now();
        $email = $this->resolveEmail($actor, $guestEmail);
        // Validated BEFORE the transaction: a misconfigured TTL must never
        // reach a business write.
        $ttlMinutes = $this->pendingTtlMinutes();

        return DB::transaction(function () use ($actor, $cartPublicId, $currency, $digest, $email, $now, $ttlMinutes): Order {
            $cart = Cart::query()->where('public_id', $cartPublicId)->lockForUpdate()->first();

            if ($cart === null || ! $this->owns($actor, $cart)) {
                throw CheckoutException::cartUnavailable();
            }

            // The replay is resolved BEFORE any cart state rule: a converted
            // cart is the normal state of a successful first call.
            $existing = Order::query()->where('checkout_idempotency_hash', $digest)->lockForUpdate()->first();

            if ($existing !== null) {
                return $this->resolveReplay($existing, $cart, $actor, $currency, $email);
            }

            $this->assertNewOrderEmail($email);

            if (Order::query()->where('cart_id', $cart->id)->exists()) {
                throw CheckoutException::of(
                    CheckoutRefusalReason::CartAlreadyCheckedOut,
                    'This cart has already been checked out.',
                );
            }

            if ($cart->status !== CartStatus::Active) {
                throw CheckoutException::of(CheckoutRefusalReason::CartNotActive, 'This cart is no longer active.');
            }

            if ($cart->expires_at !== null && $now->greaterThan($cart->expires_at)) {
                throw CheckoutException::of(CheckoutRefusalReason::CartExpired, 'This cart has expired.');
            }

            $this->lockCatalogue($cart);
            $bundleComponentCounts = $this->validateBundles($cart);

            $quote = $this->quote($cart, $currency, $now);
            $order = $this->createOrder($cart, $actor, $email, $quote, $digest, $now, $ttlMinutes);
            $this->createItems($order, $quote, $bundleComponentCounts, $now);

            $cart->forceFill(['status' => CartStatus::Converted])->save();

            // Surface any deferred violation here, inside the transaction, so a
            // refusal is a clean rollback rather than a failure at COMMIT.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            return $order;
        });
    }

    private function owns(User|Visitor $actor, Cart $cart): bool
    {
        return $actor instanceof User
            ? $cart->user_id !== null && $cart->user_id === $actor->id
            : $cart->user_id === null && $cart->visitor_id !== null && $cart->visitor_id === $actor->id;
    }

    private function resolveEmail(User|Visitor $actor, ?string $guestEmail): string
    {
        // An account's email is authoritative; a guest must supply one.
        $email = $actor instanceof User ? (string) $actor->email : (string) $guestEmail;
        $email = trim($email);

        if ($email === ''
            || mb_strlen($email) > self::MAX_HISTORICAL_CUSTOMER_EMAIL_LENGTH
            || ! $this->isValidHistoricalEmail($email)) {
            throw CheckoutException::of(CheckoutRefusalReason::InvalidEmail, 'A valid email address is required.');
        }

        return $email;
    }

    private function assertNewOrderEmail(string $email): void
    {
        if (mb_strlen($email) > self::MAX_NEW_CUSTOMER_EMAIL_LENGTH) {
            throw CheckoutException::of(CheckoutRefusalReason::InvalidEmail, 'A valid email address is required.');
        }
    }

    /**
     * Preserve the historical 320-character envelope for exact idempotent
     * replays. New addresses still use PHP's established validation contract;
     * the extended branch accepts only an ASCII dot-atom and DNS-safe labels.
     */
    private function isValidHistoricalEmail(string $email): bool
    {
        if (mb_strlen($email) <= self::MAX_NEW_CUSTOMER_EMAIL_LENGTH) {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }

        if (preg_match('/\A[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*@(.+)\z/D', $email, $matches) !== 1) {
            return false;
        }

        $localPartLength = strpos($email, '@');
        $domain = $matches[1];

        if ($localPartLength === false || $localPartLength > 64 || strlen($domain) > 255) {
            return false;
        }

        foreach (explode('.', $domain) as $label) {
            if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * An identical replay returns the stored Order untouched. The Order — not
     * the cart, which may have changed since — is the authoritative truth.
     */
    private function resolveReplay(Order $existing, Cart $cart, User|Visitor $actor, string $currency, string $email): Order
    {
        $sameActor = $actor instanceof User
            ? $existing->user_id === $actor->id
            : $existing->visitor_id === $actor->id && $existing->user_id === null;

        $identical = $existing->cart_id === $cart->id
            && $sameActor
            && $existing->currency === $currency
            && $existing->coupon_id === $cart->coupon_id
            && mb_strtolower((string) $existing->customer_email) === mb_strtolower($email);

        if (! $identical) {
            throw CheckoutException::of(
                CheckoutRefusalReason::IdempotencyConflict,
                'This idempotency key was already used for a different checkout.',
            );
        }

        return $existing;
    }

    /**
     * Deterministic lock order: cart products by id, then bundle pivots and
     * their children by id. Locking the CHILD rows is what makes D-031 Q1=C
     * enforceable — a concurrent soft delete must wait for this transaction.
     */
    private function lockCatalogue(Cart $cart): void
    {
        $productIds = $cart->items()->orderBy('id')->pluck('product_id')->unique()->sort()->values()->all();

        if ($productIds === []) {
            throw CheckoutException::of(CheckoutRefusalReason::CartEmpty, 'This cart is empty.');
        }

        Product::withTrashed()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Validates every bundle under lock and returns the expected component
     * count per bundle product id.
     *
     * @return array<int, int>
     */
    private function validateBundles(Cart $cart): array
    {
        $bundleIds = Product::withTrashed()
            ->whereIn('id', $cart->items()->pluck('product_id'))
            ->where('type', ProductType::Bundle)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $counts = [];

        foreach ($bundleIds as $bundleId) {
            $childIds = DB::table('product_bundles')
                ->where('bundle_id', $bundleId)
                ->orderBy('child_product_id')
                ->lockForUpdate()
                ->pluck('child_product_id')
                ->all();

            if ($childIds === []) {
                throw CheckoutException::of(
                    CheckoutRefusalReason::BundleEmpty,
                    'A bundle in this cart has no component and cannot be delivered.',
                );
            }

            $children = Product::withTrashed()
                ->whereIn('id', $childIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($children->count() !== count($childIds)) {
                throw self::bundleComponentUnavailable();
            }

            foreach ($children as $child) {
                // Q1 = C: refuse loudly. Filtering a soft-deleted component
                // would produce a partial snapshot the database cannot detect.
                if ($child->deleted_at !== null || $child->type === ProductType::Bundle) {
                    throw self::bundleComponentUnavailable();
                }
            }

            $counts[$bundleId] = count($childIds);
        }

        return $counts;
    }

    private static function bundleComponentUnavailable(): CheckoutException
    {
        return CheckoutException::of(
            CheckoutRefusalReason::BundleComponentUnavailable,
            'A bundle in this cart contains a component that can no longer be sold.',
        );
    }

    private function quote(Cart $cart, string $currency, CarbonImmutable $now): PricedQuote
    {
        $coupon = $cart->coupon_id === null ? null : Coupon::query()->find($cart->coupon_id);

        try {
            return $this->pricing->quote($cart, $currency, $coupon, $now);
        } catch (PricingException $exception) {
            throw CheckoutException::of(match ($exception->reason) {
                PricingRefusalReason::EmptyCart => CheckoutRefusalReason::CartEmpty,
                PricingRefusalReason::ProductUnavailable => CheckoutRefusalReason::ProductUnavailable,
                PricingRefusalReason::PriceUnavailable => CheckoutRefusalReason::PriceUnavailable,
                default => CheckoutRefusalReason::CouponUnavailable,
            }, $exception->getMessage());
        }
    }

    /**
     * How long a pending order stays claimable (D-032). Configuration only —
     * never a request input — and validated strictly: an invalid value is a
     * server misconfiguration, not a client mistake.
     */
    private function pendingTtlMinutes(): int
    {
        $configured = config('checkout.pending_ttl_minutes');

        // is_numeric() alone would accept "1.5", " 30" and 1.0; the TTL must be
        // a whole number of minutes.
        $valid = (is_int($configured) && ! is_bool($configured))
            || (is_string($configured) && preg_match('/\A[1-9][0-9]*\z/', $configured) === 1);

        if (! $valid) {
            throw self::misconfiguredTtl();
        }

        $minutes = (int) $configured;

        if ($minutes < 1 || $minutes > self::MAX_PENDING_TTL_MINUTES) {
            throw self::misconfiguredTtl();
        }

        return $minutes;
    }

    private static function misconfiguredTtl(): CheckoutException
    {
        return CheckoutException::of(
            CheckoutRefusalReason::IntegrityFailure,
            'The checkout could not be completed.',
        );
    }

    private function createOrder(
        Cart $cart,
        User|Visitor $actor,
        string $email,
        PricedQuote $quote,
        string $digest,
        CarbonImmutable $now,
        int $ttlMinutes,
    ): Order {
        $snapshot = $quote->couponSnapshot;

        $attributes = [
            'public_id' => (string) Str::uuid(),
            'cart_id' => $cart->id,
            'checkout_idempotency_hash' => $digest,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'visitor_id' => $actor instanceof Visitor ? $actor->id : $cart->visitor_id,
            'customer_email' => $email,
            'coupon_id' => $snapshot?->couponId,
            'coupon_code_snapshot' => $snapshot?->codeSnapshot,
            'coupon_discount_type_snapshot' => $snapshot?->discountTypeSnapshot,
            'coupon_percent_basis_points_snapshot' => $snapshot?->percentBasisPointsSnapshot,
            'coupon_fixed_amount_minor_snapshot' => $snapshot?->fixedAmountMinorSnapshot,
            'subtotal_minor' => $quote->subtotalMinor,
            'discount_minor' => $quote->discountMinor,
            'tax_minor' => $quote->taxMinor,
            'total_minor' => $quote->totalMinor,
            'currency' => $quote->currency,
            // A free order stays `pending`: reaching `paid` and consuming the
            // coupon belong to P3-D4 (D-027 point 5, D-028.2).
            'status' => OrderStatus::Pending,
            'placed_at' => $now,
            'expires_at' => $now->addMinutes($ttlMinutes),
        ];

        for ($attempt = 1; $attempt <= self::ORDER_NUMBER_ATTEMPTS; $attempt++) {
            try {
                // A 23505 puts the WHOLE PostgreSQL transaction in the aborted
                // state: a bare retry would only ever get 25P02 and lose the
                // cart lock. The nested transaction issues a real SAVEPOINT and
                // rolls back to it, leaving the outer transaction usable.
                return DB::transaction(
                    fn (): Order => Order::query()->create($attributes + ['order_number' => $this->orderNumbers->generate($now)]),
                );
            } catch (Throwable $exception) {
                // Only a GENUINE 23505 on exactly orders_order_number_unique is
                // retryable (P3-D2.1). A message that merely contains the name
                // proves nothing and must never spend an attempt.
                $isCollision = PostgresConstraintViolation::isUniqueViolationOf(
                    $exception,
                    'orders_order_number_unique',
                );

                if ($attempt === self::ORDER_NUMBER_ATTEMPTS || ! $isCollision) {
                    throw $this->translateWriteFailure($exception);
                }
            }
        }

        throw CheckoutException::of(CheckoutRefusalReason::IntegrityFailure, 'Could not allocate an order number.');
    }

    /**
     * @param  array<int, int>  $bundleComponentCounts
     */
    private function createItems(Order $order, PricedQuote $quote, array $bundleComponentCounts, CarbonImmutable $now): void
    {
        foreach ($quote->lines as $line) {
            // Straight from the quote: nothing is recomputed here.
            $item = OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $line->productId,
                'purchased_product_id' => $line->productId,
                'product_name_snapshot' => $line->productNameSnapshot,
                'product_slug_snapshot' => $line->productSlugSnapshot,
                'product_type_snapshot' => $line->productTypeSnapshot,
                'unit_price_minor' => $line->unitPriceMinor,
                'quantity' => $line->quantity,
                'line_subtotal_minor' => $line->lineSubtotalMinor,
                'line_discount_minor' => $line->lineDiscountMinor,
                'line_total_minor' => $line->lineTotalMinor,
                'currency' => $quote->currency,
            ]);

            if ($line->productTypeSnapshot !== ProductType::Bundle->value) {
                continue;
            }

            $this->snapshotBundle($item->id, $line->productId, $bundleComponentCounts[$line->productId] ?? 0, $now);
        }
    }

    /**
     * ONE `INSERT ... SELECT` per bundle: a single statement is a single
     * snapshot of the pivot, exhaustive by construction (D-029.3 point 3).
     * Nothing is filtered — a soft-deleted component has already been refused.
     */
    private function snapshotBundle(int $orderItemId, int $bundleProductId, int $expected, CarbonImmutable $now): void
    {
        $inserted = DB::affectingStatement(<<<'SQL'
            INSERT INTO order_item_bundle_components
                (order_item_id, child_product_id, child_product_name_snapshot, child_product_slug_snapshot, created_at)
            SELECT ?, c.id, c.name, c.slug, ?
            FROM product_bundles pb
            JOIN products c ON c.id = pb.child_product_id
            WHERE pb.bundle_id = ?
            SQL, [$orderItemId, $now, $bundleProductId]);

        if ($expected < 1 || $inserted !== $expected) {
            throw CheckoutException::of(
                CheckoutRefusalReason::BundleSnapshotMismatch,
                'The bundle composition changed during checkout.',
            );
        }
    }

    private function translateWriteFailure(Throwable $exception): CheckoutException
    {
        // A business refusal requires a genuine PostgreSQL 23505 on the exact
        // constraint — never a substring of some message (P3-D2.1).
        $constraint = PostgresConstraintViolation::constraintName($exception);

        if ($constraint === 'orders_cart_id_unique') {
            return CheckoutException::of(
                CheckoutRefusalReason::CartAlreadyCheckedOut,
                'This cart has already been checked out.',
            );
        }

        if ($constraint === 'orders_checkout_idempotency_hash_unique') {
            return CheckoutException::of(
                CheckoutRefusalReason::IdempotencyConflict,
                'This idempotency key was already used for a different checkout.',
            );
        }

        // Never surface SQL, a constraint name or an internal id.
        return CheckoutException::of(
            CheckoutRefusalReason::IntegrityFailure,
            'The checkout could not be completed.',
        );
    }
}
