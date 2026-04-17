<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Webhook\Endpoint
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook\Endpoint;

use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Log\DebugLogger;
use Comfino\Backend\Webhook\WebhookEndpoint;
use Psr\Http\Message\ServerRequestInterface;

class Configuration extends WebhookEndpoint
{
    public function __construct(
        string $name,
        string $endpointUrl,
        private readonly ConfigurationManager $configurationManager,
        private readonly DebugLogger $debugLogger,
        private readonly string $platformName,
        private readonly string $platformVersion,
        private readonly string $pluginVersion,
        private readonly int $pluginBuildTs,
        private readonly string $databaseVersion,
        private readonly int $debugLogNumLines,
        /** @var array<string, mixed>|null */
        private readonly ?array $shopExtraVariables = null
    ) {
        parent::__construct($name, $endpointUrl);

        $this->methods = ['GET', 'POST', 'PUT', 'PATCH'];
    }

    /** @return array<string, mixed>|null */
    public function processRequest(ServerRequestInterface $serverRequest, ?string $endpointName = null): ?array
    {
        if (!$this->endpointPathMatch($serverRequest, $endpointName)) {
            throw new InvalidEndpoint('Endpoint path does not match request path.');
        }

        // Create an explicit copy to avoid modifying readonly property.
        $shopExtraVariables = $this->shopExtraVariables !== null ? [...$this->shopExtraVariables] : null;

        if ($shopExtraVariables !== null && isset($shopExtraVariables['wordpress_version'])) {
            $wpVersion = $shopExtraVariables['wordpress_version'];
            unset($shopExtraVariables['wordpress_version']);
        } else {
            $wpVersion = 'n/a';
        }

        if (strtoupper($serverRequest->getMethod()) === 'GET') {
            $responseType = $serverRequest->getQueryParams()['responseType'] ?? 'configuration';

            if ($responseType === 'debug_log') {
                return ['debug_log' => $this->debugLogger->getDebugLog($this->debugLogNumLines)];
            }

            return [
                'shop_info' => [
                    'platform' => $this->platformName,
                    'platform_version' => $this->platformVersion,
                    'plugin_version' => $this->pluginVersion,
                    'plugin_build_ts' => $this->pluginBuildTs,
                    'wordpress_version' => $wpVersion,
                    'symfony_version' => class_exists('\Symfony\Component\HttpKernel\Kernel')
                        ? \Symfony\Component\HttpKernel\Kernel::VERSION
                        : 'n/a',
                    'php_version' => PHP_VERSION,
                    'server_software' => $serverRequest->getServerParams()['SERVER_SOFTWARE'],
                    'server_name' => $serverRequest->getServerParams()['SERVER_NAME'],
                    'server_addr' => $serverRequest->getServerParams()['SERVER_ADDR'],
                    'database_version' => $this->databaseVersion,
                    'extra_variables' => $shopExtraVariables,
                ],
                'shop_configuration' => $this->configurationManager->returnConfigurationOptions(),
            ];
        }

        $this->configurationManager->updateConfigurationOptions(parent::processRequest($serverRequest, $endpointName));
        $this->configurationManager->persist();

        return null;
    }
}
