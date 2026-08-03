<?php

namespace App\Models;

use App\Enums\CrmContactOrigin;
use App\Enums\CrmContactStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmContact extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['email'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CrmMarketingConsentEvent, $this>
     */
    public function marketingConsentEvents(): HasMany
    {
        return $this->hasMany(CrmMarketingConsentEvent::class, 'contact_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'origin' => CrmContactOrigin::class,
            'status' => CrmContactStatus::class,
            'anonymized_at' => 'datetime',
        ];
    }
}
