<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Store
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Store;

use Comfino\Api\CircuitBreaker\CircuitBreakerState;
use Comfino\Api\CircuitBreaker\CircuitBreakerStoreInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Shared circuit-breaker store over any PSR-6 pool.
 *
 * This is the store the breaker needs to be worth anything on a fleet: with the process-local default, each worker
 * learns independently that Comfino is down, so the outage costs one full timeout per worker per key instead of one.
 *
 * **Shared, and close enough to exact.** PSR-6 cannot compare-and-swap, so a concurrent pair of `recordFailure()`
 * calls can lose one increment — which delays opening by one call and no more. The one place the missing atomicity is
 * visible is the half-open probe: without a swap, the claim is a blind write, so two workers whose reads interleave
 * both probes. Both costs are small and bounded, which is why this store is offered where an inexact *rate limiter*
 * store would not be (see {@see Psr6TokenBucketStore}). For a fleet where even that matters, implement
 * {@see \Comfino\Api\CircuitBreaker\AtomicCircuitBreakerStoreInterface} against a backend that can swap.
 *
 * The state is stored as a plain array, not a serialized object, so a library upgrade cannot make existing rows
 * unreadable.
 */
final class Psr6CircuitBreakerStore implements CircuitBreakerStoreInterface
{
    /** Namespace for this store's keys within the pool. */
    private const KEY_PREFIX = 'comfino_cb';

    /**
     * Retention for a breaker state, in seconds.
     *
     * Longer than any plausible open window, so an open breaker cannot silently close by expiring, and short enough
     * that a key nothing has failed on in an hour stops occupying a row. A state that does expire reads as "closed,
     * no failures", which is the safe direction: the next call goes out and the breaker learns again.
     */
    public const DEFAULT_TTL_SECONDS = 3600;

    /**
     * @param CacheItemPoolInterface $pool Backing PSR-6 pool, shared by every worker that must agree on host health
     * @param int $ttlSeconds Retention for a breaker state
     */
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidArgumentException
     */
    public function get(string $key): ?CircuitBreakerState
    {
        $item = $this->pool->getItem(Psr6StoreKey::build(self::KEY_PREFIX, $key));

        if (!$item->isHit()) {
            return null;
        }

        $stored = $item->get();

        if (!is_array($stored) || !array_key_exists('consecutiveFailures', $stored)) {
            // A row written by something else, or in a shape this version does not know. Treat it as absent.
            return null;
        }

        return new CircuitBreakerState(
            (int) $stored['consecutiveFailures'],
            isset($stored['openedAt']) ? (float) $stored['openedAt'] : null,
            isset($stored['probeStartedAt']) ? (float) $stored['probeStartedAt'] : null
        );
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidArgumentException
     */
    public function set(string $key, CircuitBreakerState $state): void
    {
        $item = $this->pool->getItem(Psr6StoreKey::build(self::KEY_PREFIX, $key));

        $item->set([
            'consecutiveFailures' => $state->consecutiveFailures,
            'openedAt' => $state->openedAt,
            'probeStartedAt' => $state->probeStartedAt,
        ]);
        $item->expiresAfter($this->ttlSeconds);

        $this->pool->save($item);
    }

    /**
     * @inheritDoc
     *
     * @throws InvalidArgumentException
     */
    public function delete(string $key): void
    {
        $this->pool->deleteItem(Psr6StoreKey::build(self::KEY_PREFIX, $key));
    }
}
