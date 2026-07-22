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

use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Clock\SystemClock;
use Throwable;

/**
 * Entry point that both the platform scheduler (cron) and opportunistic drains (e.g., inbound webhook handling) call.
 *
 * Adds a cooldown gate on top of {@see OutboundRequestQueue::process()}: after a drain stops on a transient failure,
 * further drains are suppressed for $cooldownSeconds (plus jitter, see below) so frequent opportunistic triggers
 * cannot hammer a Comfino API that is already struggling. The cooldown deadline is persisted via {@see CacheManager}
 * so it spans requests.
 *
 * Cross-install thundering herd: every shop running this SDK schedules its drain on the same wall-clock-aligned cron
 * grid (e.g. every 5 minutes, on the 5-minute marks), with no per-install offset. Without jitter, a Comfino API outage
 * affecting many shops at once would end with all of them retrying at exactly the same tick once it recovers. To spread
 * that out, the actual cooldown applied is $cooldownSeconds plus a random extra of up to half that (via
 * {@see JitterInterface}), drawn fresh on every cooldown activation — this never shortens the configured cooldown, only
 * extends it unpredictably, so different installs (and different outages on the same install) end up retrying at
 * different offsets from the base grid instead of all landing on the same tick.
 *
 * The gate fails open: if the cache is unavailable, the drain still runs (delivering queued cancellations matters more
 * than perfect rate-limiting).
 */
final class OutboundRequestQueueProcessor
{
    private const COOLDOWN_CACHE_KEY = 'outbound_queue_cooldown_until';

    /**
     * Cache tag applied to queue-infrastructure keys (e.g., the cooldown timestamp).
     *
     * Platform cache adapters MUST NOT include this tag in bulk evictions triggered by config save or module cache
     * flush — queue state must survive those operations. Only natural TTL expiry should remove these entries.
     */
    public const CACHE_TAG = 'comfino_queue';

    private ClockInterface $clock;
    private JitterInterface $jitter;

    public function __construct(
        private readonly OutboundRequestQueue $queue,
        ?ClockInterface $clock = null,
        ?JitterInterface $jitter = null,
        private readonly int $defaultBatchSize = 20,
        private readonly int $cooldownSeconds = 300
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->jitter = $jitter ?? new SystemJitter();
    }

    /**
     * Drains the queue unless a cooldown is in effect.
     *
     * @param int|null $batchSize Maximum requests to process this run (defaults to the configured batch size).
     */
    public function process(?int $batchSize = null): QueueDrainResult
    {
        if ($this->isCoolingDown()) {
            return QueueDrainResult::skipped($this->queue->pendingCount());
        }

        $result = $this->queue->process($batchSize ?? $this->defaultBatchSize);

        if ($result->stoppedOnTransientFailure) {
            $this->beginCooldown();
        }

        return $result;
    }

    private function isCoolingDown(): bool
    {
        try {
            return $this->clock->now() < (int) CacheManager::get(self::COOLDOWN_CACHE_KEY, 0);
        } catch (Throwable) {
            return false; // Fail open.
        }
    }

    private function beginCooldown(): void
    {
        try {
            /* Drawn fresh each activation so repeated outages (and different installs) don't keep landing on the same
               cron tick - see the class docblock. */
            $jitteredCooldown = $this->cooldownSeconds + $this->jitter->random((int) ceil($this->cooldownSeconds / 2));

            CacheManager::set(
                self::COOLDOWN_CACHE_KEY,
                $this->clock->now() + $jitteredCooldown,
                $jitteredCooldown,
                [self::CACHE_TAG]
            );
        } catch (Throwable) {
            // Ignore - cooldown is best-effort.
        }
    }
}
