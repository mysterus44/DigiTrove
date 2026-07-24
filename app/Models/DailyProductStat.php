<?php

namespace App\Models;

use Database\Factories\DailyProductStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-oriented rollup with the PostgreSQL key `(day, product_id, currency)`.
 */
class DailyProductStat extends Model
{
    /** @use HasFactory<DailyProductStatFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'day',
        'product_id',
        'currency',
        'views',
        'add_to_carts',
        'purchases',
        'revenue_minor',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'product_id' => 'integer',
            'views' => 'integer',
            'add_to_carts' => 'integer',
            'purchases' => 'integer',
            'revenue_minor' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
