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

use Throwable;

/**
 * Raises an operational alert when one tenant's queue partition is paused.
 *
 * A pause means the merchant's outbound traffic has stopped and will not resume on its own — the usual cause is a key
 * that was rotated on one side only, or an account that was suspended. That is a fact somebody needs to act on, and it
 * is the difference between the {@see QueueErrorDisposition::PauseTenant} disposition and the silent drop it replaced:
 * dropping was quiet, and quiet is what let a merchant's cancellations disappear for a week.
 *
 * Implement it over whatever the host already uses for operational alerts — a monitoring channel, an admin notice, an
 * incident ticket. Implementations must not throw and must not block; the drain calls this while holding a batch.
 */
interface TenantPauseReporterInterface
{
    /**
     * Reports that a tenant's partition has been paused for the remainder of this drain.
     *
     * @param string|null $tenantKey The merchant whose partition was paused; null in a single-tenant integration
     * @param QueuedRequest $request The request whose failure triggered the pause, for context
     * @param Throwable $error The failure itself
     * @param string $reason Short machine-readable cause, e.g. "credentials_rejected" or "consecutive_failures"
     */
    public function reportPaused(?string $tenantKey, QueuedRequest $request, Throwable $error, string $reason): void;
}
