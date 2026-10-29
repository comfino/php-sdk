<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Webhook\Endpoint
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook\Endpoint;

use Comfino\Backend\Webhook\StatusAdapterResolverInterface;
use Comfino\Backend\Webhook\TenantWebhookContext;
use Comfino\Shop\Order\StatusAdapterInterface;

/**
 * A resolver over a fixed tenant-to-adapter map — the smallest thing a real host's resolver is a database query away
 * from.
 */
final class MapResolver implements StatusAdapterResolverInterface
{
    /**
     * @param array<string, StatusAdapterInterface> $adapters
     */
    public function __construct(private readonly array $adapters)
    {
    }

    public function resolve(TenantWebhookContext $tenant): ?StatusAdapterInterface
    {
        return $this->adapters[$tenant->tenantKey] ?? null;
    }
}
