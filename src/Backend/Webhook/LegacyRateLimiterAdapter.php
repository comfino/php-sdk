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
 * Presents a {@see RateLimiterInterface} implementation as a {@see TenantAwareRateLimiterInterface}.
 *
 * Lets a plugin that already ships a limiter keep it unchanged while {@see WebhookManager} speaks only the newer
 * interface internally. What the adapter cannot invent is the information the old interface never carried: the tenant
 * is dropped on the floor (the wrapped limiter has nowhere to put it), and a rejection comes back with no
 * `Retry-After` and no window size, so the 429 the manager writes carries no rate-limit headers. That is a faithful
 * reflection of the wrapped limiter's knowledge, not a shortcut - and it is the reason to implement the new interface
 * directly rather than lean on this.
 */
final class LegacyRateLimiterAdapter implements TenantAwareRateLimiterInterface
{
    /**
     * @param RateLimiterInterface $limiter The tenant-blind limiter to wrap
     */
    public function __construct(private readonly RateLimiterInterface $limiter)
    {
    }

    /**
     * {@inheritDoc}
     *
     * The token cost is ignored: the wrapped interface counts requests, not tokens, so charging 2 would either
     * double-count by calling twice (and consume two slots for one request) or silently under-count. Reporting the
     * request as one request is the honest translation.
     */
    public function consume(RateLimitKey $key, int $tokens = 1): RateLimitVerdict
    {
        return $this->limiter->isAllowed($key->endpointName, $key->clientIdentifier)
            ? RateLimitVerdict::accept()
            : RateLimitVerdict::reject();
    }

    /**
     * Returns the wrapped limiter.
     */
    public function getWrappedLimiter(): RateLimiterInterface
    {
        return $this->limiter;
    }
}
