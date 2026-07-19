<?php

namespace App\Models;

use Database\Factories\DownloadGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bounded authorisation to download one purchased ProductFile.
 *
 * The database is the final authority: issuance is validated, identity/token/bounds
 * are frozen, revocation is irreversible and rows are never deletable. Only the
 * SHA-256 digest of the token lives here — the raw token is never persisted.
 * The grant status (active/expired/exhausted/revoked) is DERIVED from revoked_at,
 * expires_at and downloads_count; no status column exists (D-029.1).
 */
class DownloadGrant extends Model
{
    /** @use HasFactory<DownloadGrantFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'order_item_id',
        'product_file_id',
        'user_id',
        'token_hash',
        'expires_at',
        'max_downloads',
        'downloads_count',
        'revoked_at',
        'revoked_reason_code',
        'created_at',
    ];

    /**
     * The digest is a credential lookup key: never expose it through the model.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<ProductFile, $this>
     */
    public function productFile(): BelongsTo
    {
        return $this->belongsTo(ProductFile::class);
    }

    /**
     * Audit denormalisation only — never the authority for the beneficiary.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The consumption audit trail: every attempt logged against this grant.
     *
     * @return HasMany<DownloadLog, $this>
     */
    public function downloadLogs(): HasMany
    {
        return $this->hasMany(DownloadLog::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_downloads' => 'integer',
            'downloads_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
