<?php

namespace App\Models;

use App\Enums\CrmOrderAttributionReason;
use App\Enums\CrmOrderAttributionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmOrderAttributionOutbox extends Model
{
    protected $table = 'crm_order_attribution_outbox';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $guarded = ['*'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<CrmContact, $this> */
    public function contactSnapshot(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id_snapshot');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CrmOrderAttributionStatus::class,
            'reason' => CrmOrderAttributionReason::class,
            'attempt_count' => 'integer',
            'available_at' => 'datetime',
        ];
    }
}
