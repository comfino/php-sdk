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

use Comfino\Api\SerializerInterface;
use Comfino\Backend\Webhook\RateLimiterInterface;
use Comfino\Backend\Webhook\ReplayProtectionInterface;
use Comfino\Backend\Webhook\WebhookManager;
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
     * @param string $platformName E-commerce platform name (e.g., 'WooCommerce', 'PrestaShop', 'Magento')
     * @param string $platformVersion Platform version number
     * @param string $pluginVersion Plugin version number
     * @param string[] $apiKeys List of API keys for signature verification
     * @param ServerRequestFactoryInterface $serverRequestFactory PSR-17 server request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param UriFactoryInterface $uriFactory PSR-17 URI factory
     * @param ResponseFactoryInterface $responseFactory PSR-17 response factory
     * @param SerializerInterface $serializer JSON serializer for request/response bodies
     * @param ReplayProtectionInterface|null $replayProtection Optional replay protection implementation
     * @param RateLimiterInterface|null $rateLimiter Optional rate limiter implementation
     *
     * @return WebhookManager WebhookManager instance
     */
    public function createWebhookManager(
        string $platformName,
        string $platformVersion,
        string $pluginVersion,
        array $apiKeys,
        ServerRequestFactoryInterface $serverRequestFactory,
        StreamFactoryInterface $streamFactory,
        UriFactoryInterface $uriFactory,
        ResponseFactoryInterface $responseFactory,
        SerializerInterface $serializer,
        ?ReplayProtectionInterface $replayProtection = null,
        ?RateLimiterInterface $rateLimiter = null
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
            $rateLimiter
        );
    }
}
