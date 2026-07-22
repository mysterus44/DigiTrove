<?php

declare(strict_types=1);

namespace App\Support;

use PDOException;
use Throwable;

/**
 * Recognises a PostgreSQL uniqueness violation by its SQLSTATE **and** its exact
 * constraint name (P3-D2.1).
 *
 * A message substring proves nothing: any application exception can contain the
 * words `orders_cart_id_unique`, and a constraint named
 * `orders_order_number_unique_shadow` contains `orders_order_number_unique`.
 * Classifying on text alone let a forged message trigger a retry or a business
 * refusal it had no right to.
 *
 * Empirically captured shape of a real violation:
 *
 *   Illuminate\Database\UniqueConstraintViolationException
 *     -> PDOException
 *   both with getCode() === '23505' and
 *   errorInfo = ['23505', 7, 'ERROR:  duplicate key value violates unique
 *                constraint "orders_order_number_unique" ...']
 *
 * This primitive carries NO business policy: it answers one factual question so
 * Checkout — and later Payment — can map answers to their own refusals.
 */
final class PostgresConstraintViolation
{
    private const UNIQUE_VIOLATION = '23505';

    /** PostgreSQL always quotes the constraint name; the capture stops at the quote. */
    private const CONSTRAINT_PATTERN = '/violates unique constraint "([^"]+)"/';

    /**
     * True only when the throwable chain carries a genuine PostgreSQL 23505
     * whose constraint name equals `$constraintName` exactly.
     *
     * Fail-closed: no SQLSTATE, a different SQLSTATE, no quoted constraint, a
     * merely similar name or a plain application exception all answer false.
     */
    public static function isUniqueViolationOf(Throwable $exception, string $constraintName): bool
    {
        return self::constraintName($exception) === $constraintName;
    }

    /**
     * The exact constraint name of a genuine 23505, or null.
     */
    public static function constraintName(Throwable $exception): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $errorInfo = self::errorInfoOf($current);

            if ($errorInfo === null) {
                continue;
            }

            // The SQLSTATE is read from the STRUCTURED driver field, never
            // inferred from free text.
            if (($errorInfo[0] ?? null) !== self::UNIQUE_VIOLATION) {
                continue;
            }

            // Only the driver's own message is searched, and only after the
            // SQLSTATE is confirmed.
            $driverMessage = is_string($errorInfo[2] ?? null) ? $errorInfo[2] : $current->getMessage();

            if (preg_match(self::CONSTRAINT_PATTERN, $driverMessage, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * The driver's structured error info, when the throwable really is a
     * database error. `getCode()` alone is never enough: a plain exception can
     * hold any code, so it is only accepted on an actual PDOException-backed
     * error and only when it looks like a five-character SQLSTATE.
     *
     * @return array<int, mixed>|null
     */
    private static function errorInfoOf(Throwable $exception): ?array
    {
        $errorInfo = null;

        if ($exception instanceof PDOException) {
            $errorInfo = $exception->errorInfo;
        } elseif (property_exists($exception, 'errorInfo')) {
            // Illuminate\Database\QueryException exposes the same array.
            $errorInfo = $exception->errorInfo;
        }

        if (is_array($errorInfo) && isset($errorInfo[0]) && is_string($errorInfo[0])) {
            return $errorInfo;
        }

        if (! $exception instanceof PDOException) {
            return null;
        }

        $code = $exception->getCode();

        // A bare PDOException may only carry its code; accept it solely when it
        // has the exact shape of a SQLSTATE.
        return is_string($code) && preg_match('/\A[0-9A-Z]{5}\z/', $code) === 1
            ? [$code, null, $exception->getMessage()]
            : null;
    }
}
