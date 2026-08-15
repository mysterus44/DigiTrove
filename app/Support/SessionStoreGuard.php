<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\RedisStore;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Support\Facades\Session;
use RuntimeException;
use Throwable;

/**
 * Fail-closed session backing store for the guest cart.
 *
 * WHY A GUARD AND NOT A CONFIG CONVENTION — the same shape as `MailTransportGuard`, for
 * the same reason. `.env` sets `SESSION_DRIVER=redis`, but `config/session.php` declares
 * `env('SESSION_DRIVER', 'database')`, and this repository has NO `sessions` table and no
 * migration that creates one. A deployment that merely forgets the variable therefore
 * falls back to a driver whose table does not exist — and the guest cart, whose entire
 * ownership model lives in the session, would fail in a way that looks like a database
 * error rather than a misconfiguration.
 *
 * The name is not the proof. `SESSION_DRIVER=redis` with a broken cache binding would
 * still read "redis", so the guard resolves the store and inspects the HANDLER actually
 * in use, exactly as the mail guard walks to the transport actually resolved.
 *
 * No refusal message carries a credential, a host, a session id or a cart secret: only
 * the driver name and a reason.
 */
final class SessionStoreGuard
{
    /** Drivers that keep nothing across requests, or whose table this repository lacks. */
    public const UNSUPPORTED_DRIVERS = ['database', 'file', 'cookie', 'dynamodb', 'memcached', 'null'];

    public static function isUsable(): bool
    {
        try {
            self::assertUsable();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @throws RuntimeException when the guest cart cannot rely on the session
     */
    public static function assertUsable(): void
    {
        $driver = config('session.driver');

        if (! is_string($driver) || $driver === '') {
            throw new RuntimeException('The session store is not configured.');
        }

        // The array driver keeps state for one request only. That is exactly what a test
        // needs and exactly what production must never get, so it is admitted here ONLY
        // while the test runner is active — a condition no request can influence.
        if ($driver === 'array') {
            if (app()->runningUnitTests()) {
                return;
            }

            throw new RuntimeException('The session store keeps nothing between requests.');
        }

        if (in_array($driver, self::UNSUPPORTED_DRIVERS, true)) {
            throw new RuntimeException('The session store "'.$driver.'" is not supported.');
        }

        if ($driver !== 'redis') {
            throw new RuntimeException('The session store "'.$driver.'" is not supported.');
        }

        self::assertRedisHandlerResolves();
    }

    /**
     * Resolve the handler rather than trust the name.
     *
     * A driver called "redis" whose cache binding points elsewhere would satisfy every
     * string comparison and still lose the cart on the next request.
     */
    private static function assertRedisHandlerResolves(): void
    {
        try {
            $handler = Session::driver('redis')->getHandler();
        } catch (Throwable) {
            throw new RuntimeException('The session store could not be resolved.');
        }

        if (! $handler instanceof CacheBasedSessionHandler) {
            throw new RuntimeException('The session store could not be resolved.');
        }

        if (! $handler->getCache()->getStore() instanceof RedisStore) {
            throw new RuntimeException('The session store could not be resolved.');
        }
    }
}
