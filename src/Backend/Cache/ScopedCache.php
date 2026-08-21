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

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

/**
 * A PSR-6 pool viewed through one tenant's key prefix.
 *
 * An instance is cheap and immutable, so the natural shape is one per tenant, obtained from
 * {@see CacheManager::forScope()} and passed explicitly to whatever needs it:
 *
 *     $cache = CacheManager::forScope($installationId);
 *     $cache->set('product_types.paywall.pl', $types, 3600, ['admin_product_types']);
 *
 * Reads and writes never throw. A cache is an optimization, and an integration that dies because a cache backend is
 * briefly unavailable is strictly worse than one that runs slowly — so a read failure returns the default and a write
 * failure is dropped after a short retry.
 */
final class ScopedCache
{
    /** Attempts made for a write before giving up; a contended file or Redis lock usually clears on the second try. */
    private const MAX_WRITE_ATTEMPTS = 3;

    /** Base delay between write attempts, in microseconds (10 ms), multiplied by the attempt number. */
    private const WRITE_RETRY_DELAY_US = 10000;

    /**
     * @param CacheItemPoolInterface $pool The PSR-6 pool backing this view
     * @param string $scope Key prefix isolating this tenant's entries; empty for an unscoped (single-tenant) view
     */
    public function __construct(private readonly CacheItemPoolInterface $pool, private readonly string $scope = '')
    {
    }

    /**
     * Returns the underlying PSR-6 pool.
     *
     * Reach for this only to do something this class does not cover (tag invalidation, deferred saves). Note that keys
     * passed to the pool directly are **not** scoped — use {@see scopedKey()} to prefix them.
     */
    public function getPool(): CacheItemPoolInterface
    {
        return $this->pool;
    }

    /**
     * Returns this view's scope discriminator.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Retrieves a cached item by key, returning a default value when it is absent or the pool is unusable.
     *
     * @param string $key The cache key (scoped automatically)
     * @param mixed $default The value to return if the item is not found
     *
     * @return mixed The cached value or the default value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $item = $this->pool->getItem($this->scopedKey($key));

            return $item->isHit() ? $item->get() : $default;
        } catch (InvalidArgumentException) {
            return $default;
        } catch (Throwable) {
            /* A pool that throws on read (a dead Redis, an unwritable cache directory) must not take the request with
               it - the caller's fallback is to recompute, which is slow but correct. */
            return $default;
        }
    }

    /**
     * Stores a value, retrying briefly on a transient pool failure and giving up silently after that.
     *
     * @param string $key The cache key (scoped automatically)
     * @param mixed $value The value to cache
     * @param int $ttl Time-to-live in seconds; 0 (default) means no expiration
     * @param string[]|null $tags Optional cache tags, applied when the pool's items support tagging
     */
    public function set(string $key, mixed $value, int $ttl = 0, ?array $tags = null): void
    {
        for ($attempt = 1; $attempt <= self::MAX_WRITE_ATTEMPTS; $attempt++) {
            try {
                $item = $this->pool->getItem($this->scopedKey($key))->set($value);

                if ($ttl > 0) {
                    $item->expiresAfter($ttl);
                }

                if (!empty($tags) && $item instanceof ItemInterface) {
                    /** @phpstan-ignore-next-line Method from Symfony\Cache implementation, not in Contracts interface */
                    $item->setTags($tags);
                }

                $this->pool->save($item);

                return;
            } catch (InvalidArgumentException) {
                // An unusable key cannot become usable on a retry.
                return;
            } catch (Throwable) {
                if ($attempt >= self::MAX_WRITE_ATTEMPTS) {
                    return;
                }

                usleep(self::WRITE_RETRY_DELAY_US * $attempt);
            }
        }
    }

    /**
     * Removes an entry from this scope.
     *
     * @param string $key The cache key (scoped automatically)
     *
     * @return bool True when the entry was removed (or was already absent), false when the pool refused
     */
    public function delete(string $key): bool
    {
        try {
            return $this->pool->deleteItem($this->scopedKey($key));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Returns a view of the same pool under a different scope.
     *
     * @param string $scope The scope for the returned view
     */
    public function withScope(string $scope): self
    {
        return $scope === $this->scope ? $this : new self($this->pool, $scope);
    }

    /**
     * Prefixes a key with this view's scope.
     *
     * Characters outside `[A-Za-z0-9_.]` are replaced because PSR-6 reserves `{}()/\@:` in keys and a tenant identifier
     * taken from a URL or a shop code routinely contains one of them.
     *
     * @param string $key The unscoped key
     *
     * @return string The key as stored in the pool
     */
    public function scopedKey(string $key): string
    {
        return $this->scope === '' ? $key : preg_replace('/[^A-Za-z0-9_.]/', '_', $this->scope) . '.' . $key;
    }
}
