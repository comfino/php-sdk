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

/**
 * A handler that is told which merchant the request it is delivering belongs to.
 *
 * {@see RetryableOperationHandlerInterface::execute()} receives the payload alone, which is enough only while the
 * ambient configuration scope at delivery time is the scope the request was enqueued in. On the drain path in a
 * multi-tenant host it is not: the drain runs under the platform's cron/CLI scope (Magento: the default store;
 * PrestaShop: the default shop), so a handler holding one API client resolved from that ambient scope signs every
 * merchant's queued request with the default merchant's key. The API answers 401, the classifier reads that as a
 * permanent failure, and the request is dropped - the queue loses exactly the traffic it exists to protect, and only
 * for the tenants that are not the default one.
 *
 * {@see QueuedRequest::$tenantKey} carries the merchant identity the payload does not, so a handler implementing this
 * interface can resolve that tenant's credentials at delivery time. {@see OutboundRequestQueue} calls
 * {@see executeForTenant()} whenever a handler implements it and falls back to `execute()` otherwise, so existing
 * single-tenant handlers keep working unchanged.
 *
 * The tenant-to-credentials mapping deliberately stays on the platform side (a client factory, as in
 * {@see ReportErrorHandler}): the SDK has no way to read a host's per-store configuration, and a second SDK
 * abstraction over it would only be a longer road to the same platform callback.
 */
interface TenantAwareRetryableOperationHandlerInterface extends RetryableOperationHandlerInterface
{
    /**
     * Executes the operation on behalf of one merchant.
     *
     * @param array<string, scalar> $payload Operation arguments, as enqueued
     * @param string|null $tenantKey Merchant the request belongs to; null when the queue carries no tenant identity
     *                               for it (a single-tenant integration, or a row enqueued before the tenant key
     *                               existed) - resolve the ambient scope's credentials in that case, which is what the
     *                               request was enqueued with
     *
     * @throws \Throwable On any delivery failure (classified by the queue).
     */
    public function executeForTenant(array $payload, ?string $tenantKey): void;
}
