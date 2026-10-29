<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

/**
 * Presents a {@see ReplayProtectionInterface} implementation as a {@see TenantAwareReplayProtectionInterface}.
 *
 * Lets a plugin keep the replay store it already ships while {@see WebhookManager} speaks only the newer interface
 * internally. The tenant key and the TTL are dropped, because the wrapped interface has nowhere to put either — the
 * retention window stays whatever the wrapped implementation hard-codes, and the store stays unpartitioned. That is
 * safe for a single-shop plugin, which is the only place this adapter belongs.
 */
final class LegacyReplayProtectionAdapter implements TenantAwareReplayProtectionInterface
{
    /**
     * @param ReplayProtectionInterface $replayProtection The tenant-blind replay store to wrap
     */
    public function __construct(private readonly ReplayProtectionInterface $replayProtection)
    {
    }

    /** @inheritDoc */
    public function isDuplicate(string $signature, ?string $tenantKey = null): bool
    {
        return $this->replayProtection->isDuplicate($signature);
    }

    /** @inheritDoc */
    public function markProcessed(string $signature, ?string $tenantKey = null, ?int $ttlSeconds = null): void
    {
        $this->replayProtection->markProcessed($signature);
    }

    /**
     * {@inheritDoc}
     *
     * Always a no-op returning 0: the wrapped store has no tenant dimension, so there is no partition to purge, and
     * removing every entry would delete other merchants' replay records — a correctness regression dressed up as
     * cleanup.
     */
    public function purgeTenant(?string $tenantKey = null): int
    {
        return 0;
    }

    /**
     * Returns the wrapped replay store.
     */
    public function getWrappedReplayProtection(): ReplayProtectionInterface
    {
        return $this->replayProtection;
    }
}
