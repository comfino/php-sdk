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
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\SerializerInterface;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Delivers a queued error report to the ComfinoPay error-logging endpoint.
 *
 * Used by {@see OutboundRequestQueue::process()} on the drain path. ErrorLogger always enqueues error reports as pure
 * fire-and-forget (via {@see OutboundRequestQueue::enqueue()}) rather than attempting an inline delivery, so this
 * handler is never called on the fast path.
 *
 * The client MUST be configured for minimal timeouts / no synchronous retries (the queue is the retry mechanism).
 * {@see OutboundRequestQueueFactory} wires the same minimal-timeout client used for cancel_order.
 *
 * **Multi-tenant hosts must pass a client factory rather than a client.** The report's *content* is materialized at
 * enqueue time and is therefore already correct for the merchant it came from, but the API key it is delivered with is
 * whatever the single injected client holds - resolved from the ambient configuration scope, which on the drain path
 * is the platform's cron scope rather than the originating merchant's. Passing a
 * `Closure(?string $tenantKey): ClientInterface` instead moves that resolution to delivery time, where
 * {@see QueuedRequest::$tenantKey} (recorded by `ErrorLogger` from its own tenant scope) is available:
 *
 *     new ReportErrorHandler(
 *         static fn (?string $tenantKey): ClientInterface => $sharedClient->bind(
 *             new ApiContext($resolveApiKey($tenantKey), $resolveSandboxMode($tenantKey), tenantKey: $tenantKey)
 *         ),
 *         $serializer
 *     );
 *
 * The factory is called once per delivery attempt and receives null for a request carrying no tenant identity (a
 * single-tenant integration, or a row enqueued before the tenant key existed) - return the ambient scope's client for
 * that case, which is what the request was enqueued with.
 */
final class ReportErrorHandler implements TenantAwareRetryableOperationHandlerInterface
{
    /** Registered operation type key for this handler. */
    public const OPERATION_TYPE = 'report_error';

    /**
     * @param ClientInterface|Closure(?string): ClientInterface $client API client used to deliver the report, or a
     *                                                                  factory resolving one per tenant - required in a
     *                                                                  multi-tenant host, see the class docblock
     * @param SerializerInterface $serializer Serializer decoding the queued environment payload
     */
    public function __construct(private readonly ClientInterface|Closure $client, private readonly SerializerInterface $serializer)
    {
    }

    /**
     * Delivers the report with the ambient scope's client.
     *
     * Correct for a single-tenant integration. In a multi-tenant host the queue calls {@see executeForTenant()}
     * instead, so this path is only reached when something else invokes the handler directly.
     *
     * @param array<string, scalar> $payload Produced by ShopPluginError::toQueuePayload()
     *
     * @throws ConnectionTimeout On network timeout → Retry
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx → classified by queue
     * @throws ClientExceptionInterface On PSR-18 transport error → Retry
     */
    public function execute(array $payload): void
    {
        $this->executeForTenant($payload, null);
    }

    /**
     * @param array<string, scalar> $payload Produced by ShopPluginError::toQueuePayload()
     * @param string|null $tenantKey Merchant whose credentials the report must be delivered with
     *
     * @throws ConnectionTimeout On network timeout → Retry
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx → classified by queue
     * @throws ClientExceptionInterface On PSR-18 transport error → Retry
     * @throws RuntimeException When the injected client factory does not return a client → Retry, then dead-lettered
     */
    public function executeForTenant(array $payload, ?string $tenantKey): void
    {
        $this->resolveClient($tenantKey)->sendLoggedError(ShopPluginError::fromQueuePayload($payload, $this->serializer));
    }

    /**
     * Returns the client the report must be delivered with.
     *
     * A factory that cannot resolve the tenant should throw: the queue classifies that as transient and retries, so a
     * report enqueued while a store's configuration was mid-edit is not lost on the first drain that races it. It is
     * dead-lettered once the attempt budget runs out.
     *
     * @param string|null $tenantKey Merchant the request belongs to
     */
    private function resolveClient(?string $tenantKey): ClientInterface
    {
        if ($this->client instanceof ClientInterface) {
            return $this->client;
        }

        $client = ($this->client)($tenantKey);

        /* Checked despite the declared factory return type: a plugin's closure is written against a docblock, not a
           compiler, and returning the wrong thing here deserves a diagnostic naming the tenant rather than a fatal
           "call to a member function on stdClass" three frames away. */
        /** @phpstan-ignore instanceof.alwaysTrue */
        if (!$client instanceof ClientInterface) {
            throw new RuntimeException(
                sprintf(
                    'The report_error client factory must return a %s, got %s (tenant: %s).',
                    ClientInterface::class,
                    get_debug_type($client),
                    $tenantKey ?? '-'
                )
            );
        }

        return $client;
    }
}
