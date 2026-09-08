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
 * What an inbound webhook rate limit is counted against.
 *
 * The old limiter interface took `(string $endpointName, string $clientIdentifier)` and nothing else, which left the
 * tenant out of the key. In a host serving many merchants from one backing store that has exactly two outcomes, both
 * wrong: either the counters collide, so one merchant's webhook burst rate-limits another merchant's notifications, or
 * the host smuggles the tenant into `$clientIdentifier` and the partitioning becomes an undocumented convention that
 * the next integrator will not know about. Naming the tenant here makes the partition explicit.
 *
 * All ComfinoPay webhooks arrive from ComfinoPay's own infrastructure, so `$clientIdentifier` (the source IP)
 * identifies the *sender*, not the merchant - which is precisely why it cannot double as the tenant.
 */
final class RateLimitKey
{
    /**
     * @param string $endpointName Registered webhook endpoint the request is routed to
     * @param string $clientIdentifier Caller identity, normally the remote IP address
     * @param string|null $tenantKey Merchant this request belongs to, or null in a single-tenant integration
     */
    public function __construct(public readonly string $endpointName, public readonly string $clientIdentifier, public readonly ?string $tenantKey = null)
    {
    }

    /**
     * Returns a stable string form suitable for a cache or Redis key.
     *
     * The tenant comes first so that a prefix scan can enumerate or purge one merchant's counters.
     */
    public function toStorageKey(): string
    {
        return sprintf('%s|%s|%s', $this->tenantKey ?? '-', $this->endpointName, $this->clientIdentifier);
    }
}
