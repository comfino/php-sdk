<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Queue;

use Comfino\Api\ClientInterface;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\SerializerInterface;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Delivers a queued error report to the Comfino error-logging endpoint.
 *
 * Used by {@see OutboundRequestQueue::process()} on the drain path. ErrorLogger always enqueues error reports as pure
 * fire-and-forget (via {@see OutboundRequestQueue::enqueue()}) rather than attempting an inline delivery, so this
 * handler is never called on the fast path.
 *
 * The client MUST be configured for minimal timeouts / no synchronous retries (the queue is the retry mechanism).
 * {@see OutboundRequestQueueFactory} wires the same minimal-timeout client used for cancel_order.
 */
final class ReportErrorHandler implements RetryableOperationHandlerInterface
{
    /** Registered operation type key for this handler. */
    public const OPERATION_TYPE = 'report_error';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @param array<string, scalar> $payload Produced by ShopPluginError::toQueuePayload()
     *
     * @throws ConnectionTimeout On network timeout → Retry
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx → classified by queue
     * @throws ClientExceptionInterface On PSR-18 transport error → Retry
     */
    public function execute(array $payload): void
    {
        $this->client->sendLoggedError(ShopPluginError::fromQueuePayload($payload, $this->serializer));
    }
}
