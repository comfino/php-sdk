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
 * The outcome of verifying one inbound webhook: which tenant it belongs to, and the signature it arrived with.
 *
 * There exists so that a single {@see WebhookManager} can safely serve many tenants. The manager used to keep the
 * received and calculated signatures in instance fields, which is the same class of bug as the API client keeping the
 * API key: fine while the manager is built per request and thrown away, a cross-tenant read the moment it becomes a
 * container-shared service. Carrying the per-request facts in a value object threaded through the call means there is
 * no shared mutable state to read at the wrong moment.
 */
final class VerifiedWebhookRequest
{
    /**
     * @param TenantWebhookContext $tenant The merchant this request was verified against
     * @param string $receivedSignature The `CR-Signature` value the request carried
     * @param string $calculatedSignature The signature computed for the matching key, kept for diagnostics
     * @param string|null $clientIp The caller's address as resolved by {@see ClientIpResolver} — the same value the
     *                              rate limiter counted against and the IP allow-list judged, so an endpoint and a log
     *                              line cannot disagree with the guards about who called. Null when the request
     *                              carries no `REMOTE_ADDR`
     * @param string|null $replayKey What identifies this delivery for replay protection, from the manager's
     *                               {@see ReplayKeyExtractorInterface}. Null when the delivery cannot be identified
     *                               and was therefore not deduplicated
     */
    public function __construct(
        public readonly TenantWebhookContext $tenant,
        public readonly string $receivedSignature,
        public readonly string $calculatedSignature,
        public readonly ?string $clientIp = null,
        public readonly ?string $replayKey = null
    ) {
    }

    /**
     * Returns the tenant key this request was verified against, or null in a single-tenant integration.
     */
    public function tenantKey(): ?string
    {
        return $this->tenant->tenantKey;
    }
}
