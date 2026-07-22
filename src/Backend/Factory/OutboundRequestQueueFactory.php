<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Factory
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Factory;

use Comfino\Api\ClientInterface;
use Comfino\Api\Serializer\Json;
use Comfino\Api\SerializerInterface;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Queue\ApiTransientErrorClassifier;
use Comfino\Backend\Queue\CancelOrderHandler;
use Comfino\Backend\Queue\DeadLetterReporterInterface;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\ReportErrorHandler;
use Comfino\Backend\Queue\RetryQueueStorageInterface;
use Comfino\Backend\Queue\TransientErrorClassifierInterface;
use Psr\Log\LoggerInterface;

/**
 * Assembles a fully-wired {@see OutboundRequestQueue}, consistent with the other SDK factories.
 *
 * Platforms provide the durable storage adapter and a minimal-timeout API client; this factory wires the default
 * classifier and registers the built-in "cancel_order" handler.
 */
final class OutboundRequestQueueFactory
{
    /**
     * Creates a queue with the default Comfino-API error classifier and the built-in handlers pre-registered:
     *   - cancel_order ({@see CancelOrderHandler})
     *   - report_error ({@see ReportErrorHandler})
     *
     * @param ClientInterface $minimalTimeoutClient API client configured for minimal timeouts / no synchronous retries,
     *                                              used by both the fast path and the drain
     * @param SerializerInterface|null $serializer Serializer used to encode/decode the environment payload when
     *                                             queuing error reports; defaults to {@see Json}
     */
    public function create(
        RetryQueueStorageInterface $storage,
        ClientInterface $minimalTimeoutClient,
        ?LoggerInterface $logger = null,
        ?DeadLetterReporterInterface $deadLetterReporter = null,
        ?ClockInterface $clock = null,
        ?TransientErrorClassifierInterface $classifier = null,
        ?SerializerInterface $serializer = null,
        int $maxAttempts = 10
    ): OutboundRequestQueue {
        $serializer ??= new Json();

        $queue = new OutboundRequestQueue(
            $storage,
            $classifier ?? new ApiTransientErrorClassifier(),
            $clock,
            $logger,
            $deadLetterReporter,
            $maxAttempts,
        );

        $queue->registerHandler(CancelOrderHandler::OPERATION_TYPE, new CancelOrderHandler($minimalTimeoutClient));
        $queue->registerHandler(
            ReportErrorHandler::OPERATION_TYPE,
            new ReportErrorHandler($minimalTimeoutClient, $serializer)
        );

        return $queue;
    }
}
