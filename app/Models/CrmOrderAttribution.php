<?php

namespace App\Models;

use App\Enums\CrmOrderAttributionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmOrderAttribution extends Model
{
    protected $primaryKey = 'order_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'int';

    protected $guarded = ['*'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<CrmContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => CrmOrderAttributionSource::class,
            'attributed_at' => 'datetime',
        ];
    }
}
