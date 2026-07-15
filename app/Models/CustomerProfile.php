<?php

namespace App\Models;

use App\Enums\LifecycleStage;
use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'phone',
        'country_code',
        'locale',
        'marketing_consent',
        'consent_updated_at',
        'lifecycle_stage',
        'orders_count',
        'lifetime_value_minor',
        'first_order_at',
        'last_order_at',
        'first_touch_source',
        'first_touch_medium',
        'first_touch_campaign',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marketing_consent' => 'boolean',
            'consent_updated_at' => 'datetime',
            'lifecycle_stage' => LifecycleStage::class,
            'first_order_at' => 'datetime',
            'last_order_at' => 'datetime',
        ];
    }
}
