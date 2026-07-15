<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'type',
        'status',
        'short_description',
        'long_description',
        'cover_image_path',
        'meta_title',
        'meta_description',
        'sales_count',
        'rating_avg',
        'rating_count',
        'published_at',
    ];

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_category');
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * @return HasMany<ProductFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ProductFile::class);
    }

    /**
     * @return BelongsToMany<Coupon, $this>
     */
    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'coupon_products');
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function childProducts(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_bundles', 'bundle_id', 'child_product_id')
            ->withPivot('position');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function parentBundles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_bundles', 'child_product_id', 'bundle_id')
            ->withPivot('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'rating_avg' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }
}
