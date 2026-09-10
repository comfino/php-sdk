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
 * Replay protection for inbound webhooks, partitioned by tenant and with an explicit retention window.
 *
 * Replaces {@see ReplayProtectionInterface}, which keyed on the signature alone. Two tenants cannot actually collide
 * there — the signature is `sha3-256(apiKey . rawBody)`, so it already varies with the key — but the *store* was shared
 * and unpartitioned, which is a different problem: a shared Redis or SQL table mixes every merchant's processed
 * signatures together, cannot be purged for one merchant, and cannot be reasoned about (or sized, or alerted on) per
 * merchant. Naming the tenant fixes all three.
 *
 * The retention window used to be entirely the host's business and undocumented, which meant nobody could say how long
 * a replay stayed detectable. It is a parameter here, with a documented default.
 *
 * An existing implementation of the old interface keeps working: {@see WebhookManager} wraps it in
 * {@see LegacyReplayProtectionAdapter}.
 */
interface TenantAwareReplayProtectionInterface
{
    /**
     * Default retention for a processed signature, in seconds (24 hours).
     *
     * Sized against redelivery rather than against storage: ComfinoPay retries a webhook it believes failed, and a
     * window shorter than the retry schedule turns a legitimate duplicate-suppression into a double-processed order
     * status. A day covers the schedule with room to spare, and one signature per webhook per day is negligible
     * storage for any backing store worth using here.
     */
    public const DEFAULT_TTL_SECONDS = 86400;

    /**
     * Tells whether this signature has already been processed for this tenant.
     *
     * @param string $signature The `CR-Signature` value received with the request
     * @param string|null $tenantKey Merchant the request belongs to, or null in a single-tenant integration
     *
     * @return bool True when the request is a replay and must be rejected
     */
    public function isDuplicate(string $signature, ?string $tenantKey = null): bool;

    /**
     * Records this signature as processed for this tenant.
     *
     * @param string $signature The `CR-Signature` value received with the request
     * @param string|null $tenantKey Merchant the request belongs to, or null in a single-tenant integration
     * @param int|null $ttlSeconds Retention window; null selects {@see DEFAULT_TTL_SECONDS}
     */
    public function markProcessed(string $signature, ?string $tenantKey = null, ?int $ttlSeconds = null): void;

    /**
     * Forgets every signature recorded for one tenant.
     *
     * Exists so that a merchant's deprovisioning is a complete operation rather than a table full of orphaned hashes
     * expiring over the following day. Implementations that cannot enumerate their store by tenant may no-op, but
     * should say so.
     *
     * @param string|null $tenantKey Merchant to purge, or null for the unscoped partition
     *
     * @return int Number of entries removed, or 0 when the implementation cannot tell
     */
    public function purgeTenant(?string $tenantKey = null): int;
}
