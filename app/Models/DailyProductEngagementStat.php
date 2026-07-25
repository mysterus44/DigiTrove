<?php

namespace App\Models;

use Database\Factories\DailyProductEngagementStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Currency-free product engagement keyed by `(day, product_id)`.
 */
class DailyProductEngagementStat extends Model
{
    /** @use HasFactory<DailyProductEngagementStatFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'day',
        'product_id',
        'views',
        'add_to_carts',
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
            'updated_at' => 'immutable_datetime',
        ];
    }
}
