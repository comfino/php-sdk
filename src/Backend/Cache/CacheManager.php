<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Cache
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Cache;

use LogicException;
use Psr\Cache\CacheItemPoolInterface;

/**
 * PSR-6 cache facade used internally by the SDK.
 *
 * Call {@see init()} once during bootstrap with any PSR-6 pool implementation (e.g., FilesystemCachePool,
 * ArrayCachePool, or a framework-provided adapter).
 *
 * **In a multi-tenant process, take a {@see ScopedCache} instead of using the static accessors.** The statics hold one
 * pool and one scope, both fixed at `init()`, which makes the tenant a property of the process rather than of the
 * caller — so switching tenants means re-initializing the facade, and forgetting to means answering one merchant's
 * lookup from another merchant's cache entry. {@see forScope()} returns an instance addressed by tenant, which cannot
 * be got wrong the same way:
 *
 *     $cache = CacheManager::forScope($installationId); // Per tenant, cheap, immutable.
 *     $cache->get('product_types.paywall.pl');
 *
 * The static accessors stay for the single-shop plugins where the process really does serve one tenant, and are
 * deprecated so new code does not reach for them by default.
 */
final class CacheManager
{
    private static ?ScopedCache $cache = null;

    /**
     * Called once during bootstrap with a PSR-6 cache pool.
     *
     * @param CacheItemPoolInterface $pool The PSR-6 pool to cache in
     * @param string $scope Isolates cache entries per tenant (e.g. store/website id) when a single cache pool backend
     *                      is shared across tenants with independent API keys. Leave empty for single-tenant setups;
     *                      in a process serving several tenants, prefer {@see forScope()} over setting this
     */
    public static function init(CacheItemPoolInterface $pool, string $scope = ''): void
    {
        self::$cache = new ScopedCache($pool, $scope);
    }

    /**
     * Returns a cache view addressed by tenant scope, over the pool passed to {@see init()}.
     *
     * This is the accessor a multi-tenant host wants: the returned object carries its scope, so a caller holding it
     * cannot read or write another tenant's entries, and passing it explicitly makes the tenant visible at the call
     * site instead of implicit in process state.
     *
     * @param string $scope The tenant scope for the returned view
     *
     * @return ScopedCache A view of the initialized pool under $scope
     *
     * @throws LogicException If CacheManager is not initialized
     */
    public static function forScope(string $scope): ScopedCache
    {
        return self::cache()->withScope($scope);
    }

    /**
     * Returns the cache view configured by {@see init()}, scope included.
     *
     * @return ScopedCache The initialized view
     *
     * @throws LogicException If CacheManager is not initialized
     */
    public static function scoped(): ScopedCache
    {
        return self::cache();
    }

    /**
     * Builds a cache view over an arbitrary pool bypassing the facade entirely.
     *
     * For a host that would rather own the pool itself than register it in the process state — which, in a
     * container-based application, is every host.
     *
     * @param CacheItemPoolInterface $pool The PSR-6 pool to cache in
     * @param string $scope The tenant scope for the returned view
     *
     * @return ScopedCache A view of $pool under $scope
     */
    public static function forPool(CacheItemPoolInterface $pool, string $scope = ''): ScopedCache
    {
        return new ScopedCache($pool, $scope);
    }

    /**
     * Retrieves the initialized PSR-6 cache pool.
     *
     * @return CacheItemPoolInterface The initialized cache pool
     *
     * @throws LogicException If CacheManager is not initialized
     */
    public static function getPool(): CacheItemPoolInterface
    {
        return self::cache()->getPool();
    }

    /**
     * Retrieves a cached item by key from the process-wide scope, returning a default value if not found.
     *
     * @param string $key The cache key
     * @param mixed $default The default value to return if the item is not found
     *
     * @return mixed The cached value or the default value
     *
     * @throws LogicException If CacheManager is not initialized
     *
     * @deprecated Use `CacheManager::forScope($tenant)->get()`. The scope this reads from is process state, so in a
     *             process serving more than one tenant it is whatever the last `init()` set.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::cache()->get($key, $default);
    }

    /**
     * Sets a cached item in the process-wide scope, with a key, value, and optional TTL and tags.
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache
     * @param int $ttl The time-to-live in seconds (default: 0, no expiration)
     * @param string[]|null $tags Optional cache tags
     *
     * @throws LogicException If CacheManager is not initialized
     *
     * @deprecated Use `CacheManager::forScope($tenant)->set()`. The scope this writes to is process state, so in a
     *             process serving more than one tenant it is whatever the last `init()` set.
     */
    public static function set(string $key, mixed $value, int $ttl = 0, ?array $tags = null): void
    {
        self::cache()->set($key, $value, $ttl, $tags);
    }

    /**
     * Drops the initialized pool and scope. Reset for testing.
     */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /**
     * Returns the initialized cache view, or explains what the host forgot to do.
     *
     * @return ScopedCache The initialized view
     *
     * @throws LogicException If CacheManager is not initialized
     */
    private static function cache(): ScopedCache
    {
        if (self::$cache === null) {
            throw new LogicException(
                'CacheManager not initialized. Call CacheManager::init() with a PSR-6 pool during plugin bootstrap.'
            );
        }

        return self::$cache;
    }
}
