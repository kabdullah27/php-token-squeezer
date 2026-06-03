<?php

declare(strict_types=1);

namespace TokenSqueezer\RateLimiter;

use TokenSqueezer\Exceptions\RateLimitException;

/**
 * Per-provider sliding window rate limiter.
 *
 * Uses in-memory storage (per process/request lifecycle).
 * For multi-process environments (queue workers, etc.), replace
 * the storage backend with Redis via the static $store override.
 *
 * Configuration per provider:
 *   'rate_limit'  => 60   // max requests per window
 *   'rate_window' => 60   // window size in seconds
 */
class RateLimiter
{
    /**
     * In-memory store: [ provider => [ timestamps ] ]
     *
     * @var array<string, list<int>>
     */
    protected static array $store = [];

    /**
     * Check and record a request for the given provider.
     *
     * @throws RateLimitException if limit is exceeded
     */
    public static function check(string $provider, array $providerConfig): void
    {
        $limit  = (int) ($providerConfig['rate_limit']  ?? 0);
        $window = (int) ($providerConfig['rate_window'] ?? 60);

        // rate_limit = 0 means no limit configured → skip
        if ($limit <= 0) {
            return;
        }

        $now     = time();
        $cutoff  = $now - $window;

        // Prune timestamps outside the current window
        $timestamps = static::$store[$provider] ?? [];
        $timestamps = array_values(array_filter($timestamps, fn(int $ts) => $ts > $cutoff));

        if (count($timestamps) >= $limit) {
            // Oldest timestamp tells us when the window resets
            $retryAfter = ($timestamps[0] + $window) - $now;
            throw new RateLimitException($provider, max(1, $retryAfter));
        }

        // Record this request
        $timestamps[] = $now;
        static::$store[$provider] = $timestamps;
    }

    /**
     * Return current request count within the window for a provider.
     * Useful for monitoring / inspect().
     */
    public static function count(string $provider, int $window = 60): int
    {
        $cutoff = time() - $window;
        $timestamps = static::$store[$provider] ?? [];
        return count(array_filter($timestamps, fn(int $ts) => $ts > $cutoff));
    }

    /**
     * Reset counters for one or all providers.
     * Mainly for testing.
     */
    public static function reset(?string $provider = null): void
    {
        if ($provider === null) {
            static::$store = [];
        } else {
            unset(static::$store[$provider]);
        }
    }
}
