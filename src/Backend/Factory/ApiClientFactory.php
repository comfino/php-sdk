<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Factory
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Factory;

use Comfino\Api\Client;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\RetryPolicyInterface;
use Comfino\Api\Retry\TimeoutAwareClientInterface;
use Comfino\Api\Retry\TimeoutConfig;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Factory class for creating PSR-18-based API client instances.
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
        int $maxRetries = RetryPolicyInterface::DEFAULT_MAX_ATTEMPTS
    ): Client {
        /* Normalize timeouts to satisfy ExponentialBackoffRetryPolicy's constraint
           (transferTimeout >= 3 × connectionTimeout). */
        if ($connectionTimeout < 1) {
            $connectionTimeout = 1;
        }

        if ($transferTimeout < ExponentialBackoffRetryPolicy::MIN_TRANSFER_TIMEOUT_MULTIPLIER * $connectionTimeout) {
            $transferTimeout = ExponentialBackoffRetryPolicy::MIN_TRANSFER_TIMEOUT_MULTIPLIER * $connectionTimeout;
        }

        /* Apply the validated base timeouts to the transport layer immediately, so the very first request
           (before any retry) uses the plugin-configured values rather than the adapter's constructor defaults. */
        if ($httpClient instanceof TimeoutAwareClientInterface) {
            $httpClient->updateTimeouts($connectionTimeout, $transferTimeout);
        }

        $client = new Client(
            $httpClient,
            $requestFactory,
            $streamFactory,
            $apiKey,
            retryExecutor: new RetryExecutor(
                new ExponentialBackoffRetryPolicy(
                    new TimeoutConfig($connectionTimeout, $transferTimeout),
                    $maxRetries
                )
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
}
