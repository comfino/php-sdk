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

use Comfino\Shop\Order\StatusAdapterInterface;

/**
 * Supplies the order-status adapter for the merchant a notification was verified for.
 *
 * This is what lets {@see Endpoint\StatusNotification} — and therefore the whole webhook stack — be a container
 * service in a multi-tenant host. Constructed with a {@see \Comfino\Shop\Order\StatusManager}, the endpoint is bound
 * to one merchant's adapter forever; constructed with a resolver, it asks for the right adapter once the tenant is
 * known, which is after verification and therefore at the only moment the answer can be trusted.
 *
 * Implementations are called on the request path, so they should be cheap: look the merchant up, build an adapter, and
 * hold no state that outlives the call. A host that caches adapters per tenant should read
 * {@see \Comfino\Shop\Order\StatusManager}'s docblock first — the trap it describes (an adapter cached for the life of
 * the process, pinning whatever object graph it was built with) is exactly what a naive cache here would recreate.
 */
interface StatusAdapterResolverInterface
{
    /**
     * Returns the adapter that applies status changes for this merchant.
     *
     * @param TenantWebhookContext $tenant The merchant the request was verified for, carrying the host's own metadata
     *
     * @return StatusAdapterInterface|null The adapter, or null when this host has nothing to apply the status to —
     *                                     a merchant that has been deprovisioned since the request was signed. The
     *                                     endpoint treats null as "acknowledge and do nothing", because the sender
     *                                     retries anything else and a merchant that is gone will not come back
     */
    public function resolve(TenantWebhookContext $tenant): ?StatusAdapterInterface;
}
