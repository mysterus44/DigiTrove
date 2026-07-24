<?php

namespace App\Models;

use Database\Factories\DailyFunnelStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyFunnelStat extends Model
{
    /** @use HasFactory<DailyFunnelStatFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'day';

    protected $keyType = 'string';

    protected $fillable = [
        'day',
        'visitors',
        'sessions',
        'product_views',
        'add_to_carts',
        'checkouts',
        'purchases',
        'new_customers',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'visitors' => 'integer',
            'sessions' => 'integer',
            'product_views' => 'integer',
            'add_to_carts' => 'integer',
            'checkouts' => 'integer',
            'purchases' => 'integer',
            'new_customers' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
