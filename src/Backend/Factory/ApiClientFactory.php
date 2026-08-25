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

use Comfino\Api\ApiContext;
use Comfino\Api\CircuitBreaker\CircuitBreakerInterface;
use Comfino\Api\Client;
use Comfino\Api\RateLimit\OutboundRateLimiterInterface;
use Comfino\Api\RequestObserverInterface;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\RetryObserverInterface;
use Comfino\Api\Retry\RetryPolicyInterface;
use Comfino\Api\Retry\TimeoutAwareClientInterface;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Api\Retry\TimeoutConfigurableClientInterface;
use Comfino\Api\SharedClient;
use Comfino\Platform\ConnectorInfoInterface;
use Comfino\Platform\UserAgentBuilder;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Factory class for creating PSR-18-based API client instances.
 *
 * Two shapes are produced, and which one a host wants follows from its process model rather than from taste:
 *
 *  - {@see createClient()} / {@see createClientFromPlatformInfo()} return a credential-bound {@see Client}. One PHP
 *    process serves one shop, holds one API key and dies at the end of the request, so a client that *is* the tenant is
 *    the simplest correct thing. This is what every in-shop plugin wants.
 *  - {@see createSharedClient()} returns a stateless {@see SharedClient} that holds no credential at all; the tenant
 *    travels per call in an {@see ApiContext}. A long-lived multi-tenant host — a SaaS connector serving many
 *    merchants, a worker draining jobs for them — wants this one, because it can register the client as a container
 *    service instead of allocating a client, a retry executor and a policy for every availability probe.
 */
final class ApiClientFactory
{
    /**
     * Creates a PSR-18-based API client instance.
     *
     * @param ClientInterface $httpClient PSR-18 HTTP client implementation
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param string|null $apiKey Unique authentication key required for access to the Comfino API
     * @param string|null $userAgent Custom client User-Agent header
     * @param string|null $apiBaseUrl Custom API base URL
     * @param string|null $apiLanguage Current API language code (ISO-639-1, e.g. 'pl', 'en')
     * @param int $connectionTimeout API connection timeout in seconds
     * @param int $transferTimeout Data transfer timeout in seconds
     * @param int $maxRetries Maximum number of retry attempts on transient errors
     * @param int|null $maxTotalTransferTimeout Wall-clock ceiling in seconds on the whole retried call, or null to
     *                                          disable the budget entirely. Defaults to the api-client's own default,
     *                                          which a host on a tighter latency budget than 15 s must override — see
     *                                          {@see ExponentialBackoffRetryPolicy::getWorstCaseWallClockMs()} for the
     *                                          number this bound should be derived from
     */
    public function createClient(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?string $apiKey,
        ?string $userAgent = null,
        ?string $apiBaseUrl = null,
        ?string $apiLanguage = null,
        int $connectionTimeout = 1,
        int $transferTimeout = 3,
        int $maxRetries = RetryPolicyInterface::DEFAULT_MAX_ATTEMPTS,
        ?int $maxTotalTransferTimeout = ExponentialBackoffRetryPolicy::DEFAULT_MAX_TOTAL_TRANSFER_TIMEOUT
    ): Client {
        $timeouts = $this->normalizedTimeouts($connectionTimeout, $transferTimeout);
        $httpClient = $this->transportWithBaseTimeouts($httpClient, $timeouts);

        $client = new Client(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $apiKey,
            retryExecutor: new RetryExecutor(
                new ExponentialBackoffRetryPolicy($timeouts, $maxRetries, $maxTotalTransferTimeout)
            )
        );

        if ($userAgent !== null) {
            $client->setCustomUserAgent($userAgent);
        }

        if ($apiBaseUrl !== null) {
            $client->setCustomApiBaseUrl($apiBaseUrl);
        }

        if ($apiLanguage !== null) {
            $client->setApiLanguage($apiLanguage);
        }

        return $client;
    }

    /**
     * Creates a PSR-18-based API client pre-configured from integration metadata.
     *
     * Builds the User-Agent string via {@see UserAgentBuilder}, sets the API language, and enables sandbox mode when
     * requested.
     *
     * The parameter accepts any {@see ConnectorInfoInterface} — an in-shop {@see PlatformInfoInterface} implementation
     * as before, or a {@see HostedConnectorInfo} for a connector deployed outside the shop, which used to have to
     * hand-assemble the same User-Agent string because it could not honestly implement the platform interface.
     *
     * @param ConnectorInfoInterface $platformInfo Integration metadata provider
     * @param string|null $apiKey Unique authentication key required for access to the Comfino API
     * @param bool $sandboxMode Whether to use the sandbox API environment
     * @param ClientInterface $httpClient PSR-18 HTTP client implementation
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param int $connectionTimeout API connection timeout in seconds
     * @param int $transferTimeout Data transfer timeout in seconds
     * @param int $maxRetries Maximum number of retry attempts on transient errors
     * @param int|null $maxTotalTransferTimeout Wall-clock ceiling in seconds on the whole retried call, or null to
     *                                          disable the budget
     */
    public function createClientFromPlatformInfo(
        ConnectorInfoInterface $platformInfo,
        ?string $apiKey,
        bool $sandboxMode,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        int $connectionTimeout = 1,
        int $transferTimeout = 3,
        int $maxRetries = RetryPolicyInterface::DEFAULT_MAX_ATTEMPTS,
        ?int $maxTotalTransferTimeout = ExponentialBackoffRetryPolicy::DEFAULT_MAX_TOTAL_TRANSFER_TIMEOUT
    ): Client {
        $client = $this->createClient(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $apiKey,
            UserAgentBuilder::build($platformInfo),
            null, // apiBaseUrl — caller sets sandbox via enable/disableSandboxMode
            $platformInfo->getLanguage(),
            $connectionTimeout,
            $transferTimeout,
            $maxRetries,
            $maxTotalTransferTimeout
        );

        if ($sandboxMode) {
            $client->enableSandboxMode();
        }

        return $client;
    }

    /**
     * Creates a stateless, credential-free {@see SharedClient} for a long-lived multi-tenant host.
     *
     * Nothing about a tenant lives on the returned object: the API key, the sandbox flag, the language, the custom
     * headers and the correlation ID all travel per call inside an {@see ApiContext}, which makes signing one
     * merchant's order with another merchant's key impossible by construction rather than avoided by discipline. Build
     * the context per tenant (cheap — it is an immutable value object) and either pass it to each call or take a bound
     * view of the client with `$client->bind($context)`:
     *
     *     $shared  = $factory->createSharedClient($httpClient, $requestFactory, $streamFactory); // One service
     *     $context = new ApiContext($apiKey, $sandboxMode, tenantKey: $installationId); // Per tenant
     *     $products = $shared->getFinancialProducts($context, $criteria);
     *
     * The limiter and the circuit breaker are left at their no-op defaults on purpose: both partition by
     * `$context->tenantKey`, and both ship with process-local stores, which in a multi-worker deployment means each
     * worker learns independently that the API is down. Pass implementations backed by a **shared** store (Redis,
     * RDBMS) when adopting them, or the isolation they promise is mostly lost.
     *
     * @param ClientInterface $httpClient PSR-18 HTTP client implementation, shared across every tenant; wrap it in
     *                                    {@see CallbackTimeoutAwareClient} so per-attempt timeouts reach a transport
     *                                    that takes its timeouts as construction options (Symfony's `Psr18Client`)
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param int $connectionTimeout Base API connection timeout in seconds
     * @param int $transferTimeout Base data transfer timeout in seconds
     * @param int $maxRetries Maximum number of retry attempts on transient errors; override per call site with
     *                        {@see RequestOptions::attempts()} or {@see RequestOptions::failFast()}
     * @param int|null $maxTotalTransferTimeout Wall-clock ceiling in seconds on the whole retried call, or null to
     *                                          disable the budget
     * @param RequestObserverInterface|null $requestObserver Per-request hook carrying the tenant key, for metrics
     * @param RetryObserverInterface|null $retryObserver Per-retry hook carrying the tenant key and the give-up reason
     * @param OutboundRateLimiterInterface|null $rateLimiter Outbound limiter; back it with a shared store
     * @param CircuitBreakerInterface|null $circuitBreaker Breaker keyed by (tenant, host); back it with a shared store
     * @param int $apiVersion Default API version for calls that do not override it
     */
    public function createSharedClient(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        int $connectionTimeout = 1,
        int $transferTimeout = 3,
        int $maxRetries = RetryPolicyInterface::DEFAULT_MAX_ATTEMPTS,
        ?int $maxTotalTransferTimeout = ExponentialBackoffRetryPolicy::DEFAULT_MAX_TOTAL_TRANSFER_TIMEOUT,
        ?RequestObserverInterface $requestObserver = null,
        ?RetryObserverInterface $retryObserver = null,
        ?OutboundRateLimiterInterface $rateLimiter = null,
        ?CircuitBreakerInterface $circuitBreaker = null,
        int $apiVersion = 1
    ): SharedClient {
        $timeouts = $this->normalizedTimeouts($connectionTimeout, $transferTimeout);

        return new SharedClient(
            $this->transportWithBaseTimeouts($httpClient, $timeouts),
            $requestFactory,
            $streamFactory,
            $apiVersion,
            null,
            new RetryExecutor(
                new ExponentialBackoffRetryPolicy($timeouts, $maxRetries, $maxTotalTransferTimeout),
                null,
                $retryObserver
            ),
            $requestObserver,
            $rateLimiter,
            $circuitBreaker
        );
    }

    /**
     * Normalizes the requested timeouts to satisfy {@see ExponentialBackoffRetryPolicy}'s constraint that the transfer
     * timeout is at least {@see ExponentialBackoffRetryPolicy::MIN_TRANSFER_TIMEOUT_MULTIPLIER} times the connection
     * timeout — so a host passing an inconsistent pair gets a working client rather than an exception.
     *
     * @param int $connectionTimeout Requested connection timeout in seconds
     * @param int $transferTimeout Requested transfer timeout in seconds
     *
     * @return TimeoutConfig The normalized pair
     */
    private function normalizedTimeouts(int $connectionTimeout, int $transferTimeout): TimeoutConfig
    {
        if ($connectionTimeout < 1) {
            $connectionTimeout = 1;
        }

        $minTransferTimeout = ExponentialBackoffRetryPolicy::MIN_TRANSFER_TIMEOUT_MULTIPLIER * $connectionTimeout;

        if ($transferTimeout < $minTransferTimeout) {
            $transferTimeout = (int) $minTransferTimeout;
        }

        return new TimeoutConfig($connectionTimeout, $transferTimeout);
    }

    /**
     * Applies the validated base timeouts to the transport, so the very first request (before any retry) uses the
     * host-configured values rather than the adapter's constructor defaults.
     *
     * A {@see TimeoutConfigurableClientInterface} transport is asked for a *configured copy*; only the legacy
     * {@see TimeoutAwareClientInterface} is mutated in place. That ordering matters in a shared process: mutating a
     * container-shared transport leaves one tenant's timeouts applied to the next tenant's call, which is exactly the
     * cross-tenant bug the newer interface exists to remove.
     *
     * @param ClientInterface $httpClient The transport as supplied by the host
     * @param TimeoutConfig $timeouts Normalized base timeouts
     *
     * @return ClientInterface The transport to send with
     */
    private function transportWithBaseTimeouts(ClientInterface $httpClient, TimeoutConfig $timeouts): ClientInterface
    {
        if ($httpClient instanceof TimeoutConfigurableClientInterface) {
            return $httpClient->withTimeouts($timeouts);
        }

        if ($httpClient instanceof TimeoutAwareClientInterface) {
            $httpClient->updateTimeouts($timeouts->connectionTimeout, $timeouts->transferTimeout);
        }

        return $httpClient;
    }
}
