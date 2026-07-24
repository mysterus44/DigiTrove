<?php

namespace App\Models;

use Database\Factories\AnalyticsEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only event projection.
 *
 * PostgreSQL owns the real composite primary key `(id, occurred_at)`. Eloquent
 * uses the identity `id` only to hydrate inserts; it must never issue updates or
 * deletes, which the database rejects independently.
 */
class AnalyticsEvent extends Model
{
    /** @use HasFactory<AnalyticsEventFactory> */
    use HasFactory;

    protected $table = 'events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'occurred_at',
        'visitor_id',
        'user_id',
        'session_id',
        'event_name',
        'entity_type',
        'entity_id',
        'properties',
        'page_path',
        'referrer_host',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'device_type',
        'country_code',
        'ip_hash',
        'ip_hash_key_version',
        'created_at',
    ];

    protected $hidden = [
        'ip_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'user_id' => 'integer',
            'entity_id' => 'integer',
            'properties' => 'array',
            'ip_hash_key_version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
