<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Tests\Concerns\InteractsWithCrmDatabase;
use Tests\Support\SegmentFixtures as Fx;

uses(InteractsWithCrmDatabase::class);

/**
 * Build a cart directly. The application has NO cart creation flow (no route, no
 * controller, no service), so a fixture is the only way to obtain one — which is itself
 * the precondition D-056 records.
 */
function p6cCart(string $status = 'active'): int
{
    Fx::owner()->insert(
        "INSERT INTO carts (public_id, secret_hash, currency, status, expires_at, last_activity_at, created_at, updated_at)
         VALUES (gen_random_uuid(), md5(random()::text) || md5(random()::text), 'XOF', ?, now() + interval '1 day', now(), now(), now())",
        [$status],
    );

    return (int) Fx::owner()->selectOne('SELECT id FROM carts ORDER BY id DESC LIMIT 1')->id;
}

it('creates exactly migration 000028 and no 000035', function () {
    $root = dirname(__DIR__, 2);

    expect(glob($root.'/database/migrations/*.php'))->toHaveCount(50)
        ->and(glob($root.'/database/migrations/2026_07_14_000028*.php'))->toHaveCount(1)
        ->and(glob($root.'/database/migrations/2026_07_14_000035*.php') ?: [])->toBe([]);
});

it('adds an authoritative cart activity signal maintained by the database', function () {
    $column = Fx::owner()->selectOne(<<<'SQL'
        SELECT is_nullable, column_default
        FROM information_schema.columns
        WHERE table_name = 'carts' AND column_name = 'last_activity_at'
        SQL);

    expect($column)->not->toBeNull()
        ->and($column->is_nullable)->toBe('NO')
        ->and($column->column_default)->toContain('now()');

    // The signal is a TRIGGER, not an Eloquent $touches: no worker, command or raw
    // statement can bypass it.
    $trigger = Fx::owner()->selectOne(<<<'SQL'
        SELECT tgname FROM pg_trigger t
        JOIN pg_class c ON c.oid = t.tgrelid
        WHERE c.relname = 'cart_items' AND NOT t.tgisinternal
        SQL);

    expect($trigger?->tgname)->toBe('cart_items_touch_cart_activity_trigger');
});

it('owns the ledger with the CRM executor and gives the runtime no table access', function () {
    $owner = Fx::owner()->selectOne(<<<'SQL'
        SELECT pg_get_userbyid(relowner) AS owner
        FROM pg_class WHERE relname = 'cart_reminder_attempts' AND relkind = 'r'
        SQL);

    expect($owner?->owner)->toBe('digitrove_crm_executor');

    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
        expect((bool) Fx::owner()->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS granted',
            ['digitrove_runtime', 'public.cart_reminder_attempts', $privilege],
        )->granted)->toBeFalse("runtime must not hold {$privilege}");

        expect((bool) Fx::owner()->selectOne(
            'SELECT has_table_privilege(?, ?, ?) AS granted',
            ['public', 'public.cart_reminder_attempts', $privilege],
        )->granted)->toBeFalse("PUBLIC must not hold {$privilege}");
    }
});

it('grants the runtime EXECUTE on every reminder authority and nothing else', function () {
    $authorities = [
        'mark_abandoned_carts', 'list_cart_reminder_candidates', 'enqueue_cart_reminder',
        'list_due_cart_reminders', 'claim_cart_reminder', 'attach_cart_reminder_secret',
        'complete_cart_reminder', 'suppress_cart_reminder', 'fail_cart_reminder',
        'resolve_cart_reminder_by_secret', 'purge_cart_reminders',
    ];

    foreach ($authorities as $authority) {
        $row = Fx::owner()->selectOne(<<<'SQL'
            SELECT pg_get_userbyid(p.proowner) AS owner, p.prosecdef, p.proconfig,
                   has_function_privilege('digitrove_runtime', p.oid, 'EXECUTE') AS runtime_execute,
                   has_function_privilege('public', p.oid, 'EXECUTE') AS public_execute
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public' AND p.proname = ?
            SQL, [$authority]);

        expect($row)->not->toBeNull("authority {$authority} must exist")
            ->and($row->owner)->toBe('digitrove_crm_executor')
            ->and((bool) $row->prosecdef)->toBeTrue("{$authority} must be SECURITY DEFINER")
            ->and((bool) $row->runtime_execute)->toBeTrue()
            ->and((bool) $row->public_execute)->toBeFalse("PUBLIC must not execute {$authority}");
    }

    // The activity trigger function is internal: the runtime never calls it directly.
    expect((bool) Fx::owner()->selectOne(
        "SELECT has_function_privilege('digitrove_runtime', 'public.touch_cart_last_activity()', 'EXECUTE') AS granted"
    )->granted)->toBeFalse();
});

it('keeps every read authority STABLE so none of them can mutate', function () {
    foreach (['list_cart_reminder_candidates', 'list_due_cart_reminders', 'resolve_cart_reminder_by_secret'] as $authority) {
        expect((string) Fx::owner()->selectOne(
            'SELECT provolatile FROM pg_proc WHERE proname = ?', [$authority],
        )->provolatile)->toBe('s', "{$authority} must be STABLE");
    }
});

it('constrains status, step, digest, error code and terminal reason', function () {
    $cartId = p6cCart();

    Fx::owner()->insert(
        "INSERT INTO cart_reminder_attempts (cart_id, step, status, created_at, updated_at) VALUES (?, 1, 'pending', now(), now())",
        [$cartId],
    );
    $attemptId = (int) Fx::owner()->selectOne('SELECT id FROM cart_reminder_attempts ORDER BY id DESC LIMIT 1')->id;

    expect(fn () => Fx::owner()->update("UPDATE cart_reminder_attempts SET status = 'queued' WHERE id = ?", [$attemptId]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->update('UPDATE cart_reminder_attempts SET step = 0 WHERE id = ?', [$attemptId]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->update("UPDATE cart_reminder_attempts SET secret_hash = 'NOTAHEX' WHERE id = ?", [$attemptId]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->update("UPDATE cart_reminder_attempts SET last_error_code = 'boom' WHERE id = ?", [$attemptId]))
        ->toThrow(QueryException::class);
    // Free text is how PII leaks into an audit trail: the reason is an allowlist.
    expect(fn () => Fx::owner()->update("UPDATE cart_reminder_attempts SET terminal_reason = 'customer asked' WHERE id = ?", [$attemptId]))
        ->toThrow(QueryException::class);

    // One attempt per (cart, step), for ever: that unique index IS the idempotency key.
    expect(fn () => Fx::owner()->insert(
        "INSERT INTO cart_reminder_attempts (cart_id, step, status, created_at, updated_at) VALUES (?, 1, 'pending', now(), now())",
        [$cartId],
    ))->toThrow(QueryException::class);
});

it('requires the timestamp that matches each status', function () {
    $cartId = p6cCart();
    Fx::owner()->insert(
        "INSERT INTO cart_reminder_attempts (cart_id, step, status, created_at, updated_at) VALUES (?, 2, 'pending', now(), now())",
        [$cartId],
    );
    $id = (int) Fx::owner()->selectOne('SELECT id FROM cart_reminder_attempts ORDER BY id DESC LIMIT 1')->id;

    // A status without its timestamp is structurally impossible.
    expect(fn () => Fx::owner()->update("UPDATE cart_reminder_attempts SET status = 'sent' WHERE id = ?", [$id]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->update("UPDATE cart_reminder_attempts SET status = 'suppressed' WHERE id = ?", [$id]))
        ->toThrow(QueryException::class);
    expect(fn () => Fx::owner()->update("UPDATE cart_reminder_attempts SET status = 'claimed' WHERE id = ?", [$id]))
        ->toThrow(QueryException::class);
});
