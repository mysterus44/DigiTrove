<?php

namespace App\Models;

use Database\Factories\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponRedemption extends Model
{
    /** @use HasFactory<CouponRedemptionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'coupon_id',
        'order_id',
        'customer_key_version',
        'customer_key_hash',
        'coupon_code_snapshot',
        'discount_type_snapshot',
        'discount_minor',
        'currency',
        'redeemed_at',
    ];

    protected $hidden = [
        'customer_key_hash',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_key_version' => 'integer',
            'discount_minor' => 'integer',
            'redeemed_at' => 'datetime',
        ];
    }
}
