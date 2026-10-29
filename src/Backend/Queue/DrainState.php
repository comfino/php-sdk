<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Queue;

/**
 * Mutable tallies for one {@see OutboundRequestQueue::process()} run.
 *
 * Internal bookkeeping, deliberately not part of the public surface: it exists so the drain loop and the per-request
 * delivery path can share one set of counters without threading half a dozen by-reference parameters through them, and
 * so the per-tenant failure streaks live somewhere with a name rather than in a local array.
 *
 * @internal
 */
final class DrainState
{
    /** Requests delivered or treated as already delivered. */
    public int $processed = 0;

    /** Requests dropped for good. */
    public int $deadLettered = 0;

    /** Requests kept for a later run after a failure. */
    public int $requeued = 0;

    /** Requests skipped because their per-item backoff had not elapsed. */
    public int $notDue = 0;

    /** @var list<string|null> Tenants whose partitions were held for the rest of the run. */
    public array $pausedTenants = [];

    /** @var array<string, int> Consecutive failures per tenant partition within this run. */
    private array $failureStreaks = [];

    /**
     * Records a failure for a tenant and returns its resulting consecutive-failure count.
     *
     * @param string|null $tenantKey The tenant that failed
     *
     * @return int Consecutive failures for that tenant in this run
     */
    public function recordFailure(?string $tenantKey): int
    {
        $key = $this->key($tenantKey);

        return $this->failureStreaks[$key] = ($this->failureStreaks[$key] ?? 0) + 1;
    }

    /**
     * Clears a tenant's failure streak after a successful delivery.
     *
     * The streak has to be *consecutive* to mean anything: a tenant whose requests mostly succeed is not a tenant to
     * pause, however many isolated failures it accumulates over a long drain.
     *
     * @param string|null $tenantKey The tenant that succeeded
     */
    public function clearFailures(?string $tenantKey): void
    {
        unset($this->failureStreaks[$this->key($tenantKey)]);
    }

    /**
     * Marks a tenant's partition as paused for the remainder of the run.
     *
     * @param string|null $tenantKey The tenant being paused
     */
    public function pauseTenant(?string $tenantKey): void
    {
        if (!in_array($tenantKey, $this->pausedTenants, true)) {
            $this->pausedTenants[] = $tenantKey;
        }
    }

    /**
     * Returns the array key standing for a tenant, since null is not usable as one.
     *
     * @param string|null $tenantKey The tenant, or null for the unscoped partition
     */
    private function key(?string $tenantKey): string
    {
        return $tenantKey ?? "\0unscoped";
    }
}
