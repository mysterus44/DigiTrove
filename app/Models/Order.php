<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'order_number',
        'cart_id',
        'checkout_idempotency_hash',
        'user_id',
        'visitor_id',
        'customer_email',
        'customer_name_snapshot',
        'billing_country_code',
        'coupon_id',
        'coupon_code_snapshot',
        'coupon_discount_type_snapshot',
        'coupon_percent_basis_points_snapshot',
        'coupon_fixed_amount_minor_snapshot',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'total_minor',
        'currency',
        'status',
        'placed_at',
        'expires_at',
        'paid_at',
        'cancelled_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'referrer_host',
        'ip_hash',
    ];

    protected $hidden = [
        'checkout_idempotency_hash',
        'ip_hash',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Visitor, $this>
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasOne<CouponRedemption, $this>
     */
    public function couponRedemption(): HasOne
    {
        return $this->hasOne(CouponRedemption::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'coupon_percent_basis_points_snapshot' => 'integer',
            'coupon_fixed_amount_minor_snapshot' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'placed_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
