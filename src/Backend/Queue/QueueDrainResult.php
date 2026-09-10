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
 * Summary of a single {@see OutboundRequestQueue::process()} run.
 */
final class QueueDrainResult
{
    /** @var list<string|null> */
    public readonly array $pausedTenants;

    /**
     * @param int $processed Requests successfully delivered (or treated as already-delivered) and removed
     * @param int $deadLettered Requests dropped permanently (max attempts exceeded or permanent error)
     * @param int $requeued Requests left pending after a transient failure (kept for the next run)
     * @param int $remaining Pending requests still in the store after this run
     * @param bool $stoppedOnTransientFailure True if a tenant was paused and nothing at all got through, so the API
     *                                        looks unavailable rather than one tenant merely being misconfigured
     * @param bool $skipped True if the run was skipped entirely (e.g. cooldown gate)
     * @param list<string|null> $pausedTenants Tenants whose partitions were held for the rest of this run
     * @param int $notDue Requests skipped because their per-item backoff had not elapsed yet
     */
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $deadLettered = 0,
        public readonly int $requeued = 0,
        public readonly int $remaining = 0,
        public readonly bool $stoppedOnTransientFailure = false,
        public readonly bool $skipped = false,
        array $pausedTenants = [],
        public readonly int $notDue = 0
    ) {
        $this->pausedTenants = $pausedTenants;
    }

    /**
     * Builds the result of a run that never started.
     *
     * @param int $remaining Pending requests in the store
     */
    public static function skipped(int $remaining): self
    {
        return new self(remaining: $remaining, skipped: true);
    }

    /**
     * Returns true when at least one tenant's partition was paused during the run.
     */
    public function hasPausedTenants(): bool
    {
        return $this->pausedTenants !== [];
    }
}
