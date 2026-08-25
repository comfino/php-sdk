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
 * Rate limiter interface for webhook requests.
 *
 * @deprecated Use {@see TenantAwareRateLimiterInterface}. This interface has no tenant dimension, so a shared backing
 *             store either collides across merchants - one merchant's webhook burst throttling another's notifications
 *             - or forces the host to smuggle the tenant into $clientIdentifier, which is a workaround and an
 *             undocumented one. It also has no notion of cost, and its boolean verdict cannot produce a spec-correct
 *             429: there is no Retry-After to report and no window size to publish. Existing implementations keep
 *             working; {@see WebhookManager} adapts them via {@see LegacyRateLimiterAdapter}.
 */
interface RateLimiterInterface
{
    /**
     * Check if the webhook request is allowed.
     *
     * @param string $endpointName The name of the webhook endpoint
     * @param string $clientIdentifier The identifier of the client making the request
     *
     * @return bool True if the request is allowed, false otherwise
     */
    public function isAllowed(string $endpointName, string $clientIdentifier): bool;
}
