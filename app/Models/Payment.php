<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'order_id',
        'provider',
        'provider_payment_reference',
        'idempotency_key_hash',
        'attempt_number',
        'amount_minor',
        'currency',
        'status',
        'provider_status',
        'provider_method',
        'provider_metadata',
        'failure_code',
        'failure_message_sanitized',
        'initiated_at',
        'processing_at',
        'succeeded_at',
        'failed_at',
        'cancelled_at',
        'expired_at',
        'last_verified_at',
    ];

    protected $hidden = [
        'idempotency_key_hash',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<PaymentWebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'attempt_number' => 'integer',
            'amount_minor' => 'integer',
            'provider_metadata' => 'array',
            'initiated_at' => 'datetime',
            'processing_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }
}
