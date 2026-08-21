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

use Comfino\Api\SerializerInterface;
use Comfino\Backend\Webhook\IpWhitelistInterface;
use Comfino\Backend\Webhook\RateLimiterInterface;
use Comfino\Backend\Webhook\ReplayProtectionInterface;
use Comfino\Backend\Webhook\StaticApiKeyResolver;
use Comfino\Backend\Webhook\TenantAwareRateLimiterInterface;
use Comfino\Backend\Webhook\TenantAwareReplayProtectionInterface;
use Comfino\Backend\Webhook\WebhookManager;
use Comfino\Backend\Webhook\WebhookTenantResolverInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Factory class for creating WebhookManager instances.
 */
final class WebhookManagerFactory
{
    /**
     * Creates a WebhookManager instance with the provided PSR-17 factories and serializer.
     *
     * The fourth argument decides how the manager identifies a merchant, and the choice matters:
     *
     *  - A `string[]` of API keys is the single-shop shape. It is wrapped in a {@see StaticApiKeyResolver}, so every
     *    request resolves to that one shop. Passing several merchants' keys here is the mistake the resolver exists to
     *    prevent — any authentic Comfino signature would then authorize a request for any of them.
     *  - A {@see WebhookTenantResolverInterface} is the multi-tenant shape: it reads the merchant from the request and
     *    returns that merchant's key alone, so verification is narrowed to one tenant's secret before it happens.
     *
     * @param string $platformName E-commerce platform name (e.g., 'WooCommerce', 'PrestaShop', 'Magento')
     * @param string $platformVersion Platform version number
     * @param string $pluginVersion Plugin version number
     * @param string[]|WebhookTenantResolverInterface $apiKeys One shop's API keys, or a tenant resolver
     * @param ServerRequestFactoryInterface $serverRequestFactory PSR-17 server request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param UriFactoryInterface $uriFactory PSR-17 URI factory
     * @param ResponseFactoryInterface $responseFactory PSR-17 response factory
     * @param SerializerInterface $serializer JSON serializer for request/response bodies
     * @param ReplayProtectionInterface|TenantAwareReplayProtectionInterface|null $replayProtection Optional replay
     *                                                                                              protection
     * @param RateLimiterInterface|TenantAwareRateLimiterInterface|null $rateLimiter Optional rate limiter
     * @param IpWhitelistInterface|null $ipWhitelist Optional IP whitelist implementation
     * @param int|null $replayTtlSeconds Retention for processed signatures; null selects the interface default
     * @param int $rateLimitTokens Token cost charged per request against the limiter
     *
     * @return WebhookManager WebhookManager instance
     */
    public function createWebhookManager(
        string $platformName,
        string $platformVersion,
        string $pluginVersion,
        array|WebhookTenantResolverInterface $apiKeys,
        ServerRequestFactoryInterface $serverRequestFactory,
        StreamFactoryInterface $streamFactory,
        UriFactoryInterface $uriFactory,
        ResponseFactoryInterface $responseFactory,
        SerializerInterface $serializer,
        ReplayProtectionInterface|TenantAwareReplayProtectionInterface|null $replayProtection = null,
        RateLimiterInterface|TenantAwareRateLimiterInterface|null $rateLimiter = null,
        ?IpWhitelistInterface $ipWhitelist = null,
        ?int $replayTtlSeconds = null,
        int $rateLimitTokens = 1
    ): WebhookManager {
        return new WebhookManager(
            $platformName,
            $platformVersion,
            $pluginVersion,
            $apiKeys,
            $serverRequestFactory,
            $streamFactory,
            $uriFactory,
            $responseFactory,
            $serializer,
            $replayProtection,
            $rateLimiter,
            $ipWhitelist,
            $replayTtlSeconds,
            $rateLimitTokens
        );
    }
}
