<?php

namespace App\Models;

use Database\Factories\OrderItemBundleComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a bundle's components at purchase time.
 *
 * The database is the final authority: rows are validated on insert, frozen on
 * update and never deletable. Nothing here may copy the pivot or mutate a snapshot.
 */
class OrderItemBundleComponent extends Model
{
    /** @use HasFactory<OrderItemBundleComponentFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_item_id',
        'child_product_id',
        'child_product_name_snapshot',
        'child_product_slug_snapshot',
        'created_at',
    ];

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function childProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'child_product_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }
}
