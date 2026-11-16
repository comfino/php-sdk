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

use Closure;
use Comfino\Api\ClientInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

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

    /**
     * @param ClientInterface $apiClient API client sending the cancellation
     * @param (Closure(string, array<string, scalar>): bool)|null $isOrderClosed Called at delivery time with the order ID and the payload; true means
     *                                                                           ComfinoPay already treats the order as closed (it reached a terminal
     *                                                                           status after the cancel was queued). The cancel then completes as a
     *                                                                           no-op instead of being sent, so it cannot collide with the API's own
     *                                                                           lock on the order
     * @param LoggerInterface|null $logger Receives a debug line for every suppressed cancel
     */
    public function __construct(
        private readonly ClientInterface $apiClient,
        private readonly ?Closure $isOrderClosed = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function execute(array $payload): void
    {
        $orderId = (string) ($payload['orderId'] ?? '');

        if ($orderId === '') {
            throw new InvalidArgumentException('The cancel_order payload requires a non-empty "orderId".');
        }

        if ($this->isOrderClosed !== null && ($this->isOrderClosed)($orderId, $payload)) {
            $this->logger?->debug('[REQUEST_QUEUE] cancel_order not sent: order already closed at ComfinoPay.', ['orderId' => $orderId, 'guard' => 'terminal']);

            return;
        }

        $this->apiClient->cancelOrder($orderId);
    }
}
