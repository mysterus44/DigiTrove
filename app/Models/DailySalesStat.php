<?php

namespace App\Models;

use Database\Factories\DailySalesStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-oriented rollup. Eloquent has no native composite-key persistence, so
 * writes must use an explicit query keyed by `(day, currency)`.
 */
class DailySalesStat extends Model
{
    /** @use HasFactory<DailySalesStatFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'day',
        'currency',
        'orders_count',
        'gross_revenue_minor',
        'discount_minor',
        'tax_minor',
        'refunds_minor',
        'net_revenue_minor',
        'average_order_minor',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'orders_count' => 'integer',
            'gross_revenue_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'refunds_minor' => 'integer',
            'net_revenue_minor' => 'integer',
            'average_order_minor' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
