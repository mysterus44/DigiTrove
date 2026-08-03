<?php

namespace App\Models;

use App\Enums\MarketingChannel;
use App\Enums\MarketingConsentAction;
use App\Enums\MarketingConsentSource;
use App\Enums\MarketingPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmMarketingConsentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['idempotency_hash'];

    /**
     * @return BelongsTo<CrmContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => MarketingChannel::class,
            'purpose' => MarketingPurpose::class,
            'action' => MarketingConsentAction::class,
            'source' => MarketingConsentSource::class,
            'recorded_at' => 'datetime',
        ];
    }
}
