<?php

declare(strict_types=1);

use App\Support\PostgresConstraintViolation;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| P3-D2.1 — Exact classification of PostgreSQL uniqueness violations
|--------------------------------------------------------------------------
|
| Empirically captured shape of a real violation (probe on the running image):
|
|   level 0  Illuminate\Database\UniqueConstraintViolationException
|            getCode() = '23505'  (string)
|            errorInfo = ['23505', 7, 'ERROR:  duplicate key value violates
|                         unique constraint "orders_order_number_unique" ...']
|   level 1  PDOException, identical code and errorInfo
|
| The classification must require BOTH the exact SQLSTATE and the exact
| constraint name. A substring of the message is not evidence of anything.
*/

function p3d21PdoException(string $sqlstate, string $message): PDOException
{
    $exception = new PDOException("SQLSTATE[{$sqlstate}]: {$message}");
    $exception->errorInfo = [$sqlstate, 7, $message];

    return $exception;
}

function p3d21QueryException(string $sqlstate, string $message): QueryException
{
    return new QueryException('pgsql', 'insert into "orders" ...', [], p3d21PdoException($sqlstate, $message));
}

function p3d21UniqueViolation(string $constraint): QueryException
{
    return p3d21QueryException(
        '23505',
        "ERROR:  duplicate key value violates unique constraint \"{$constraint}\"\nDETAIL:  Key (x)=(y) already exists."
    );
}

// ---------------------------------------------------------------------------
// Recognised: real 23505 with the exact constraint name
// ---------------------------------------------------------------------------

it('recognises a real unique violation on the exact constraint', function (string $constraint) {
    expect(PostgresConstraintViolation::isUniqueViolationOf(p3d21UniqueViolation($constraint), $constraint))
        ->toBeTrue();
})->with([
    ['orders_order_number_unique'],
    ['orders_cart_id_unique'],
    ['orders_checkout_idempotency_hash_unique'],
]);

it('reads the constraint name through the previous exception chain', function () {
    $wrapped = new RuntimeException('wrapper', 0, p3d21UniqueViolation('orders_cart_id_unique'));

    expect(PostgresConstraintViolation::isUniqueViolationOf($wrapped, 'orders_cart_id_unique'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// RED D — a name that merely CONTAINS the expected one is not that constraint
// ---------------------------------------------------------------------------

it('refuses a constraint whose name only resembles the expected one', function (string $actual) {
    expect(PostgresConstraintViolation::isUniqueViolationOf(
        p3d21UniqueViolation($actual),
        'orders_order_number_unique',
    ))->toBeFalse();
})->with([
    ['orders_order_number_unique_shadow'],
    ['prefix_orders_order_number_unique'],
    ['orders_order_number_uniq'],
    ['ORDERS_ORDER_NUMBER_UNIQUE'],
]);

// ---------------------------------------------------------------------------
// RED E — the right constraint name under the wrong SQLSTATE proves nothing
// ---------------------------------------------------------------------------

it('refuses the right constraint name carried by a different SQLSTATE', function (string $sqlstate) {
    $exception = p3d21QueryException(
        $sqlstate,
        'ERROR:  something about unique constraint "orders_cart_id_unique"',
    );

    expect(PostgresConstraintViolation::isUniqueViolationOf($exception, 'orders_cart_id_unique'))->toBeFalse();
})->with([['23514'], ['40001'], ['25P02'], ['42501'], ['23503'], ['']]);

// ---------------------------------------------------------------------------
// RED A/B/C — an applicative exception is never a database classification
// ---------------------------------------------------------------------------

it('refuses a plain application exception whose message spoofs a constraint name', function (string $constraint) {
    $spoofed = new RuntimeException(
        "boom: duplicate key value violates unique constraint \"{$constraint}\""
    );

    expect(PostgresConstraintViolation::isUniqueViolationOf($spoofed, $constraint))->toBeFalse();
})->with([
    ['orders_order_number_unique'],
    ['orders_cart_id_unique'],
    ['orders_checkout_idempotency_hash_unique'],
]);

it('refuses a database exception carrying no errorInfo and no usable code', function () {
    $bare = new PDOException('duplicate key value violates unique constraint "orders_cart_id_unique"');

    expect(PostgresConstraintViolation::isUniqueViolationOf($bare, 'orders_cart_id_unique'))->toBeFalse();
});

it('refuses a 23505 whose message quotes no constraint at all', function () {
    $exception = p3d21QueryException('23505', 'ERROR:  duplicate key value violates unique constraint');

    expect(PostgresConstraintViolation::isUniqueViolationOf($exception, 'orders_cart_id_unique'))->toBeFalse();
});

it('never infers a sqlstate from free text', function () {
    // The words are there, the structured SQLSTATE is not.
    $exception = new RuntimeException('SQLSTATE[23505] unique constraint "orders_cart_id_unique"');

    expect(PostgresConstraintViolation::isUniqueViolationOf($exception, 'orders_cart_id_unique'))->toBeFalse();
});
