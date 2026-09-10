<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Store
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Store;

use Comfino\Api\RateLimit\TokenBucket;
use Comfino\Api\RateLimit\TokenBucketStoreInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Shared token-bucket store over any PSR-6 pool, so the outbound limiter can be adopted without writing one.
 *
 * The outbound limiter shipped in 3.0.0 with a process-local store, which means every worker gets its own full bucket
 * and the configured rate is multiplied by the worker count. Making it shared was left to the host, and the host's
 * first move is a PSR-6 pool it already has. This is that, done once and correctly serialized.
 *
 * **Read this before relying on it: it is shared but not exact.** PSR-6 has no compare-and-swap and no atomic
 * increment — `getItem()` then `save()` is the only shape it offers — so two workers reserving at the same moment both
 * read the same bucket and the second `save()` erases the first. The limiter then admits more than its configured rate
 * under concurrency, which is exactly when it matters. Concretely:
 *
 *  - **Good enough** for protecting a ComfinoPay account's own quota from a runaway loop, where being within a
 *    factor of the worker count of the target rate is fine and the alternative is no limit at all.
 *  - **Not good enough** when the rate is a hard contractual ceiling. That needs
 *    {@see \Comfino\Api\RateLimit\AtomicTokenBucketStoreInterface}, implemented against a backend that can do the
 *    compare and the write in one operation — a Redis Lua script, an `UPDATE … WHERE tokens = ? AND updated_at = ?`.
 *    {@see \Comfino\Api\RateLimit\TokenBucketRateLimiter::isExact()} answers which of the two a host wired up.
 *
 * The bucket is stored as a plain array rather than a serialized object on purpose: a cache row outlives a library
 * upgrade, and a `TokenBucket` written by one version and unserialized by another is a class of bug nobody wants in a
 * rate limiter.
 */
final class Psr6TokenBucketStore implements TokenBucketStoreInterface
{
    /** Namespace for this store's keys within the pool. */
    private const KEY_PREFIX = 'comfino_rl';

    /**
     * Retention for an untouched bucket, in seconds.
     *
     * A bucket refills on its own, so an expired one is indistinguishable from a full one — expiry costs nothing but a
     * forgotten burst allowance. An hour keeps the pool from accumulating a row per key that was used once.
     */
    public const DEFAULT_TTL_SECONDS = 3600;

    /**
     * @param CacheItemPoolInterface $pool Backing PSR-6 pool, shared by every worker that must share the limit
     * @param int $ttlSeconds Retention for an untouched bucket
     */
    public function __construct(private readonly CacheItemPoolInterface $pool, private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS)
    {
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidArgumentException
     */
    public function get(string $key): ?TokenBucket
    {
        $item = $this->pool->getItem(Psr6StoreKey::build(self::KEY_PREFIX, $key));

        if (!$item->isHit()) {
            return null;
        }

        $stored = $item->get();

        if (!is_array($stored) || !isset($stored['tokens'], $stored['updatedAt'])) {
            // A row written by something else, or by a version that stored a different shape. Treat it as absent.
            return null;
        }

        return new TokenBucket((float) $stored['tokens'], (float) $stored['updatedAt']);
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidArgumentException
     */
    public function set(string $key, TokenBucket $bucket): void
    {
        $item = $this->pool->getItem(Psr6StoreKey::build(self::KEY_PREFIX, $key));

        $item->set(['tokens' => $bucket->tokens, 'updatedAt' => $bucket->updatedAt]);
        $item->expiresAfter($this->ttlSeconds);

        $this->pool->save($item);
    }
}
