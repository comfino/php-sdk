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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides which merchant an inbound webhook belongs to before its signature is verified.
 *
 * This is the ordering that matters. {@see WebhookManager} used to take a flat `string[] $apiKeys` and try each one
 * until a signature matched, which in a multi-tenant host means passing every merchant's key — and then *any* valid
 * ComfinoPay signature authorizes a request for *every* merchant. The request really is from ComfinoPay, so
 * verification passes; it is then handled as whichever tenant the URL named. Resolving the tenant first and
 * verifying against that tenant's key only makes the confusion impossible.
 *
 * Implementations read the tenant from wherever the integration put it — a path segment, a header, a query parameter —
 * and return that tenant's keys and nothing else. A tenant that cannot be identified, or whose keys cannot be loaded,
 * must resolve to **null**: the manager then rejects the request. Never fall back to "try the other keys", and never
 * return a context with a placeholder key; both reintroduce exactly what this interface removes.
 *
 * Single-shop plugins do not need to write one — {@see StaticApiKeyResolver} wraps the configured keys and reproduces
 * the previous behavior in one line of wiring.
 */
interface WebhookTenantResolverInterface
{
    /**
     * Resolves the tenant an inbound request belongs to.
     *
     * Called before any signature check, so the request is untrusted: treat everything read from it as attacker input.
     * The tenant identifier it carries selects *which* key to verify against, which is safe — it cannot grant access,
     * only decide whose secret must match. Resolving must not have side effects; it happens on every request, including
     * rejected ones.
     *
     * @param ServerRequestInterface $request The untrusted inbound request
     *
     * @return TenantWebhookContext|null The tenant's context, or null to reject the request (401/404 — never "try the
     *                                   other keys")
     */
    public function resolve(ServerRequestInterface $request): ?TenantWebhookContext;

    /**
     * Resolves a tenant by identifier, with no request in hand.
     *
     * Needed for the outbound direction: {@see WebhookManager::getCrSignature()} signs a value the plugin is about to
     * hand to ComfinoPay, and there is no inbound request to read the tenant from.
     *
     * A null $tenantKey asks for "the one tenant this integration serves". Return a context for it when that question
     * has an answer — a single-shop plugin — and null when it does not, so a multi-tenant host cannot accidentally sign
     * with an arbitrary merchant's key.
     *
     * @param string|null $tenantKey Merchant to resolve, or null for the integration's single/default tenant
     *
     * @return TenantWebhookContext|null The tenant's context, or null when it cannot be resolved
     */
    public function resolveByTenantKey(?string $tenantKey = null): ?TenantWebhookContext;
}
