<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
    /**
     * @param int $processed Requests successfully delivered (or treated as already-delivered) and removed
     * @param int $deadLettered Requests dropped permanently (max attempts exceeded or permanent error)
     * @param int $requeued Requests left pending after a transient failure (kept for the next run)
     * @param int $remaining Pending requests still in the store after this run
     * @param bool $stoppedOnTransientFailure True if the run stopped early because the API looked unavailable
     * @param bool $skipped True if the run was skipped entirely (e.g. cooldown gate)
     */
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $deadLettered = 0,
        public readonly int $requeued = 0,
        public readonly int $remaining = 0,
        public readonly bool $stoppedOnTransientFailure = false,
        public readonly bool $skipped = false
    ) {
    }

    public static function skipped(int $remaining): self
    {
        return new self(remaining: $remaining, skipped: true);
    }
}
