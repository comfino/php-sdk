<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

/**
 * Rate limiter for inbound webhook requests, keyed by the tenant and answering with a usable verdict.
 *
 * Replaces {@see RateLimiterInterface}, which was tenant-blind, had no notion of cost, and answered with a bare
 * boolean that cannot produce a spec-correct 429. An existing implementation of the old interface keeps working:
 * {@see WebhookManager} wraps it in {@see LegacyRateLimiterAdapter}.
 *
 * Implementations must not block. A webhook handler that waits for a token has turned a rate limit into a latency
 * problem for Comfino's delivery infrastructure, which will time out and redeliver - making the burst worse.
 */
interface TenantAwareRateLimiterInterface
{
    /**
     * Consumes $tokens against the given key and reports what happened.
     *
     * @param RateLimitKey $key What the limit is counted against (endpoint, caller, tenant)
     * @param int $tokens Cost of this request; more than 1 for a request known to be expensive
     *
     * @return RateLimitVerdict Whether the request may proceed, and the headroom/retry facts behind that answer
     */
    public function consume(RateLimitKey $key, int $tokens = 1): RateLimitVerdict;
}
