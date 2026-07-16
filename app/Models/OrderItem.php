<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name_snapshot',
        'product_slug_snapshot',
        'product_type_snapshot',
        'unit_price_minor',
        'quantity',
        'line_subtotal_minor',
        'line_discount_minor',
        'line_total_minor',
        'currency',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<OrderItemBundleComponent, $this>
     */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(OrderItemBundleComponent::class);
    }

    /**
     * @return HasMany<DownloadGrant, $this>
     */
    public function downloadGrants(): HasMany
    {
        return $this->hasMany(DownloadGrant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
            'line_subtotal_minor' => 'integer',
            'line_discount_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }
}
