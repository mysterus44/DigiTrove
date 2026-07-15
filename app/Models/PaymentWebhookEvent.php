<?php

namespace App\Models;

use App\Enums\WebhookProcessingStatus;
use Database\Factories\PaymentWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    /** @use HasFactory<PaymentWebhookEventFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'external_event_id',
        'payment_id',
        'event_type',
        'payload_hash',
        'filtered_payload',
        'signature_verified',
        'processing_status',
        'received_at',
        'processed_at',
        'failed_at',
        'retention_until',
        'processing_error_sanitized',
    ];

    /**
     * The raw-body fingerprint is a security-sensitive audit value; hidden from
     * serialisation, consistent with the hash masking on the financial models.
     */
    protected $hidden = [
        'payload_hash',
    ];

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processing_status' => WebhookProcessingStatus::class,
            'filtered_payload' => 'array',
            'signature_verified' => 'boolean',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'retention_until' => 'datetime',
        ];
    }
}
