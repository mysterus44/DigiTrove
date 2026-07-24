<?php

namespace App\Models;

use Database\Factories\AnalyticsSessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnalyticsSession extends Model
{
    /** @use HasFactory<AnalyticsSessionFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'visitor_id',
        'user_id',
        'started_at',
        'last_seen_at',
        'ended_at',
        'entry_path',
        'exit_path',
        'page_views',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'device_type',
        'country_code',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'page_views' => 'integer',
            'started_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
