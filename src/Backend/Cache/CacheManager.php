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
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

/**
 * PSR-6 cache facade used internally by the SDK.
 *
 * Call CacheManager::init() once during bootstrap with any PSR-6 pool implementation
 * (e.g., FilesystemCachePool, ArrayCachePool, or a framework-provided adapter).
 */
final class CacheManager
{
    private static ?CacheItemPoolInterface $pool = null;

    /**
     * Called once during bootstrap with a PSR-6 cache pool.
     */
    public static function init(CacheItemPoolInterface $pool): void
    {
        self::$pool = $pool;
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
        if (self::$pool === null) {
            throw new LogicException(
                'CacheManager not initialized. Call CacheManager::init() with a PSR-6 pool during plugin bootstrap.'
            );
        }

        return self::$pool;
    }

    /**
     * Retrieves a cached item by key, returning a default value if not found.
     *
     * @param string $key The cache key
     * @param mixed $default The default value to return if the item is not found
     *
     * @return mixed The cached value or the default value
     *
     * @throws \InvalidArgumentException If the key is empty
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $item = self::getPool()->getItem($key);

            return $item->isHit() ? $item->get() : $default;
        } catch (InvalidArgumentException) {
            return $default;
        }
    }

    /**
     * Sets a cached item with a key, value, and optional TTL and tags.
     *
     * @param string $key The cache key
     * @param mixed $value The value to cache
     * @param int $ttl The time-to-live in seconds (default: 0, no expiration)
     * @param string[]|null $tags Optional cache tags
     *
     * @throws \InvalidArgumentException If the key is empty
     */
    public static function set(string $key, mixed $value, int $ttl = 0, ?array $tags = null): void
    {
        $maxRetries = 3; // 3 attempts with exponential backoff
        $retryDelay = 10000; // 10,000 micro seconds = 10 ms = 0.01 seconds (base delay for each retry)

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $item = self::getPool()->getItem($key)->set($value);

                if ($ttl > 0) {
                    $item->expiresAfter($ttl);
                }

                if (!empty($tags) && $item instanceof ItemInterface) {
                    /** @phpstan-ignore-next-line Method from Symfony\Cache implementation, not in Contracts interface */
                    $item->setTags($tags);
                }

                self::getPool()->save($item);

                return;
            } catch (InvalidArgumentException) {
                return;
            } catch (Throwable) {
                if ($attempt >= $maxRetries) {
                    return;
                }

                usleep($retryDelay * $attempt);
            }
        }
    }

    /**
     * Reset for testing.
     */
    public static function reset(): void
    {
        self::$pool = null;
    }
}
