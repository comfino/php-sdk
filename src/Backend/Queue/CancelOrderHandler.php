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

use Comfino\Api\ClientInterface;
use InvalidArgumentException;

/**
 * Handler for the "cancel_order" operation: cancels a ComfinoPay application via the api client.
 *
 * The platform constructs this with a client configured for MINIMAL timeouts and no synchronous retries
 * (e.g., ApiClientFactory::createClient(..., connectionTimeout: 1, transferTimeout: 2, maxRetries: 1)), so neither the
 * fast-path submit nor the drain blocks for long - the durable queue is the retry mechanism.
 */
final class CancelOrderHandler implements RetryableOperationHandlerInterface
{
    /** Registered operation type key for this handler. */
    public const OPERATION_TYPE = 'cancel_order';

    public function __construct(private readonly ClientInterface $apiClient)
    {
    }

    public function execute(array $payload): void
    {
        $orderId = (string) ($payload['orderId'] ?? '');

        if ($orderId === '') {
            throw new InvalidArgumentException('The cancel_order payload requires a non-empty "orderId".');
        }

        $this->apiClient->cancelOrder($orderId);
    }
}
