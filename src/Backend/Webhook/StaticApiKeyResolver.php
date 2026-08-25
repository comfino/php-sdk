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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolver for an integration that serves exactly one merchant: every request resolves to the configured keys.
 *
 * This is the correct shape for a plugin installed in a shop — the process serves one merchant by construction, so
 * "which tenant is this" has a constant answer, and the keys are the one or two the merchant configured (two during a
 * key rotation, or a production and a sandbox key).
 *
 * It is **not** a shortcut for a multi-tenant host. Handing this resolver every merchant's key restores exactly the
 * behavior {@see WebhookTenantResolverInterface} exists to remove: any valid Comfino signature would authorize a
 * request for any merchant. A host serving more than one merchant writes a resolver that reads the tenant from the
 * request and returns that tenant's key alone.
 *
 * Keys that are null or empty are dropped, and a resolver left with none resolves to null — so a plugin whose API key
 * is not configured yet rejects webhooks instead of verifying them against `sha3-256('' . $payload)`, which any caller
 * can compute.
 */
final class StaticApiKeyResolver implements WebhookTenantResolverInterface
{
    private readonly ?TenantWebhookContext $context;

    /**
     * @param string[] $apiKeys The single merchant's API keys, most-current first; unusable entries are discarded
     * @param string|null $tenantKey Optional identifier for this merchant, carried into the rate limiter and the replay
     *                               store so their entries are partitioned even in a shared backing store
     * @param array<string, mixed> $metadata Host-defined extras carried on the resolved context
     */
    public function __construct(array $apiKeys, ?string $tenantKey = null, array $metadata = [])
    {
        $usableKeys = array_values(
            /** @phpstan-ignore-next-line The is_string() guard is deliberate: the declared string[] is not enforced */
            array_filter($apiKeys, static fn ($apiKey): bool => is_string($apiKey) && $apiKey !== '')
        );

        $this->context = $usableKeys !== [] ? new TenantWebhookContext($tenantKey, $usableKeys, $metadata) : null;
    }

    /**
     * {@inheritDoc}
     *
     * The request is not read: there is only one tenant to resolve to.
     */
    public function resolve(ServerRequestInterface $request): ?TenantWebhookContext
    {
        return $this->context;
    }

    /**
     * {@inheritDoc}
     *
     * Answers for the configured tenant, and for null (the "single tenant of this integration" question). A different
     * tenant key resolves to null — this resolver knows about one merchant, and pretending otherwise would sign with
     * the wrong key.
     */
    public function resolveByTenantKey(?string $tenantKey = null): ?TenantWebhookContext
    {
        if ($this->context === null) {
            return null;
        }

        return ($tenantKey === null || $tenantKey === $this->context->tenantKey) ? $this->context : null;
    }

    /**
     * Returns true when at least one usable API key was configured.
     *
     * Useful in a plugin's health check: a false here means every inbound webhook is being rejected.
     */
    public function hasUsableApiKey(): bool
    {
        return $this->context !== null;
    }
}
