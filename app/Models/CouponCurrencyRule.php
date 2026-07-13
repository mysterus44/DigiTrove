<?php

namespace App\Models;

use Database\Factories\CouponCurrencyRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponCurrencyRule extends Model
{
    /** @use HasFactory<CouponCurrencyRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'coupon_id',
        'currency',
        'fixed_amount_minor',
        'min_order_minor',
        'max_discount_minor',
    ];

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
            'fixed_amount_minor' => 'integer',
            'min_order_minor' => 'integer',
            'max_discount_minor' => 'integer',
        ];
    }
}
