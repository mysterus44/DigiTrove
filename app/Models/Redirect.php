<?php

namespace App\Models;

use Database\Factories\RedirectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * P7. A permanent redirect from a legacy path to its replacement.
 *
 * The invariants live in PostgreSQL, not here: internal absolute paths only (an open
 * redirect is the defect P6-D2 closed on `/r/{code}`, and a redirect table is a far easier
 * target), no self-loop, and NO CHAIN — `redirects_no_chain_trigger` refuses 301 → 301,
 * which costs crawl budget and link equity on every hop.
 */
class Redirect extends Model
{
    /** @use HasFactory<RedirectFactory> */
    use HasFactory;

    protected $fillable = [
        'from_path',
        'to_path',
        'status_code',
    ];

    protected function casts(): array
    {
        return ['status_code' => 'integer'];
    }
}
