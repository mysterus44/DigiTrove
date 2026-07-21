<?php

namespace App\Models;

use Database\Factories\DownloadLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One commercial download attempt against a DownloadGrant (D-029.5).
 *
 * The database is the final authority: G5 pairs the `started` insertion with the
 * exact +1 of the grant counter atomically, freezes the attempt secret digest and
 * every audit field, and only allows started -> completed|denied transitions.
 * `completed` records the successful handoff to the delivery mechanism, never the
 * full reception by the client (R3A). Rows are purgeable only through G6, once
 * terminal and past their explicit retention. Only digests ever live here: the
 * raw attempt secret and the raw IP are never persisted.
 */
class DownloadLog extends Model
{
    /** @use HasFactory<DownloadLogFactory> */
    use HasFactory;

    /** The audit trail has a birth date and no generic update timestamp. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'download_grant_id',
        'status',
        'quota_consumed',
        'attempt_token_hash',
        'attempt_expires_at',
        'denial_reason_code',
        'ip_hash',
        'ip_hash_key_version',
        'user_agent',
        'bytes_sent',
        'terminal_at',
        'retention_until',
        'created_at',
    ];

    /**
     * Digests are credential lookup keys and pseudonymised identity: never
     * expose them through the model.
     *
     * @var list<string>
     */
    protected $hidden = [
        'attempt_token_hash',
        'ip_hash',
    ];

    /**
     * @return BelongsTo<DownloadGrant, $this>
     */
    public function downloadGrant(): BelongsTo
    {
        return $this->belongsTo(DownloadGrant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quota_consumed' => 'boolean',
            'ip_hash_key_version' => 'integer',
            'bytes_sent' => 'integer',
            'attempt_expires_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
            'retention_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
