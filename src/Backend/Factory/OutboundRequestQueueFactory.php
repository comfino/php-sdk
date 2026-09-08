<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Factory
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Factory;

use Closure;
use Comfino\Api\ClientInterface;
use Comfino\Api\Serializer\Json;
use Comfino\Api\SerializerInterface;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Queue\ApiTransientErrorClassifier;
use Comfino\Backend\Queue\CancelOrderHandler;
use Comfino\Backend\Queue\DeadLetterReporterInterface;
use Comfino\Backend\Queue\JitterInterface;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\ReportErrorHandler;
use Comfino\Backend\Queue\RetryQueueStorageInterface;
use Comfino\Backend\Queue\TenantAwareRetryableOperationHandlerInterface;
use Comfino\Backend\Queue\TenantPauseReporterInterface;
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
     * Creates a queue with the default ComfinoPay-API error classifier and the built-in handlers pre-registered:
     *   - cancel_order ({@see CancelOrderHandler})
     *   - report_error ({@see ReportErrorHandler})
     *
     * @param ClientInterface $minimalTimeoutClient API client configured for minimal timeouts / no synchronous retries,
     *                                              used by both the fast path and the drain
     * @param SerializerInterface|null $serializer Serializer used to encode/decode the environment payload when
     *                                             queuing error reports; defaults to {@see Json}
     * @param int $maxAttempts Drain attempts allowed per request before it is dead-lettered
     * @param JitterInterface|null $jitter Injectable randomness for the per-item retry backoff
     * @param int $baseRetryDelaySeconds First per-item retry delay; doubles per attempt up to the ceiling
     * @param int $maxRetryDelaySeconds Ceiling for the per-item retry delay
     * @param int $maxConsecutiveTenantFailures Consecutive failures within one drain before a tenant's partition is
     *                                          paused for the rest of the run; 1 (default) reproduces the pre-3.0
     *                                          stop-on-first-failure behavior, scoped to one tenant
     * @param TenantPauseReporterInterface|null $pauseReporter Raises the operational alert behind a paused partition.
     *                                                         A multi-tenant host should always pass one: a pause means
     *                                                         a merchant's outbound traffic has stopped and will not
     *                                                         resume without a human
     * @param int $tenantPauseSeconds How far forward a paused tenant's due requests are deferred
     * @param Closure(?string): ClientInterface|null $tenantClientFactory Resolves the API client for one merchant at
     *                                              delivery time, from the tenant key recorded on the queued request.
     *                                              **A multi-tenant host must pass this**: without it the drain
     *                                              delivers every merchant's queued error report with
     *                                              $minimalTimeoutClient's key - the ambient (cron-scope) merchant's -
     *                                              and the 401 that follows is classified as permanent, so the report
     *                                              is dropped. See {@see ReportErrorHandler}. The returned client must
     *                                              use the same minimal timeouts, since the queue is the retry
     *                                              mechanism. Only report_error uses it: cancel_order's payload is
     *                                              platform-shaped, so hosts register their own tenant-aware handler
     *                                              for it (see {@see TenantAwareRetryableOperationHandlerInterface})
     */
    public function create(
        RetryQueueStorageInterface $storage,
        ClientInterface $minimalTimeoutClient,
        ?LoggerInterface $logger = null,
        ?DeadLetterReporterInterface $deadLetterReporter = null,
        ?ClockInterface $clock = null,
        ?TransientErrorClassifierInterface $classifier = null,
        ?SerializerInterface $serializer = null,
        int $maxAttempts = 10,
        ?JitterInterface $jitter = null,
        int $baseRetryDelaySeconds = 60,
        int $maxRetryDelaySeconds = 3600,
        int $maxConsecutiveTenantFailures = 1,
        ?TenantPauseReporterInterface $pauseReporter = null,
        int $tenantPauseSeconds = 900,
        ?Closure $tenantClientFactory = null
    ): OutboundRequestQueue {
        $serializer ??= new Json();

        $queue = new OutboundRequestQueue(
            $storage,
            $classifier ?? new ApiTransientErrorClassifier(),
            $clock,
            $logger,
            $deadLetterReporter,
            $maxAttempts,
            $jitter,
            $baseRetryDelaySeconds,
            $maxRetryDelaySeconds,
            $maxConsecutiveTenantFailures,
            $pauseReporter,
            $tenantPauseSeconds
        );

        $queue->registerHandler(CancelOrderHandler::OPERATION_TYPE, new CancelOrderHandler($minimalTimeoutClient));
        $queue->registerHandler(ReportErrorHandler::OPERATION_TYPE, new ReportErrorHandler($tenantClientFactory ?? $minimalTimeoutClient, $serializer));

        return $queue;
    }
}
