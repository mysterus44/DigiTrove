<?php

namespace App\Models;

use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'payment_id',
        'provider',
        'provider_refund_reference',
        'idempotency_key_hash',
        'amount_minor',
        'currency',
        'status',
        'reason_code',
        'reason_note_sanitized',
        'initiated_by_user_id',
        'provider_status',
        'provider_metadata',
        'requested_at',
        'processing_at',
        'succeeded_at',
        'failed_at',
        'cancelled_at',
        'last_verified_at',
    ];

    protected $hidden = [
        'idempotency_key_hash',
    ];

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'provider_metadata' => 'array',
            'requested_at' => 'datetime',
            'processing_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }
}
