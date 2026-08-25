<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Queue;

use Comfino\Backend\Queue\RetryableOperationHandlerInterface;
use Throwable;

/**
 * Handler that fails for configured tenants, or for configured order ids, and succeeds otherwise.
 *
 * The queue hands a handler the payload and nothing else, so these tests put the tenant in the payload - which is what
 * a real handler needs too, since it has to know whose API key to call with.
 */
final class PerTenantHandler implements RetryableOperationHandlerInterface
{
    /** @var array<int, array<string, scalar>> Payloads the handler was asked to deliver, in order. */
    public array $attempted = [];

    /** @var array<int, array<string, scalar>> Payloads that were delivered successfully, in order. */
    public array $delivered = [];

    /** @var array<string, Throwable> Order id => failure. */
    private array $failuresByOrderId = [];

    /**
     * @param array<string, Throwable> $failuresByTenant Tenant key => failure raised for every one of its items
     */
    public function __construct(private readonly array $failuresByTenant = [])
    {
    }

    /**
     * Makes the handler fail for one specific order id, whatever its tenant.
     */
    public function failFor(string $orderId, Throwable $error): void
    {
        $this->failuresByOrderId[$orderId] = $error;
    }

    /** @inheritDoc */
    public function execute(array $payload): void
    {
        $this->attempted[] = $payload;

        $orderId = (string) ($payload['orderId'] ?? '');
        $tenant = (string) ($payload['tenant'] ?? '');

        if (isset($this->failuresByOrderId[$orderId])) {
            throw $this->failuresByOrderId[$orderId];
        }

        if (isset($this->failuresByTenant[$tenant])) {
            throw $this->failuresByTenant[$tenant];
        }

        $this->delivered[] = $payload;
    }
}
