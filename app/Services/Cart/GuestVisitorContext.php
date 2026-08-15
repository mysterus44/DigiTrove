<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Visitor;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * The anonymous identity a guest cart belongs to.
 *
 * TWO RULES SHAPE THIS CLASS.
 *
 * A visitor row is created on the first MUTATION, never on a read. Otherwise every
 * crawler hitting `GET /cart` would mint a row, and the abandonment metrics P6-C depends
 * on would drown in visitors who never intended to buy anything.
 *
 * The identifier is read from the server session and NEVER from the request. Accepting a
 * visitor id from the client would turn an analytics identifier into an authorisation
 * token — anyone could claim another visitor's cart by guessing a UUID.
 */
final class GuestVisitorContext
{
    public const SESSION_KEY = 'storefront.visitor_id';

    /** The current visitor id, or null when this browser has never mutated a cart. */
    public function currentId(): ?string
    {
        $id = Session::get(self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The visitor for a mutation, created on first use.
     *
     * A session may name a visitor that no longer exists — rows are erasable by design
     * (D-057 forbade any permanent veto on a purge). The lookup therefore falls back to
     * creating a fresh identity rather than failing on a dangling reference.
     */
    public function forMutation(): Visitor
    {
        $id = $this->currentId();

        if ($id !== null) {
            $existing = Visitor::query()->find($id);

            if ($existing !== null) {
                return $existing;
            }
        }

        $visitor = Visitor::query()->create([
            'id' => (string) Str::uuid(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Session::put(self::SESSION_KEY, $visitor->id);

        return $visitor;
    }
}
