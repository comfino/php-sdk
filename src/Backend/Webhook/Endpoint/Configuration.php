<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Webhook\Endpoint
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook\Endpoint;

use Closure;
use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Log\DebugLogger;
use Comfino\Backend\Log\SensitiveDataProcessor;
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
        private readonly ?array $shopExtraVariables = null,
        private readonly ?Closure $shopEnvironmentReportProvider = null,
        private readonly bool $debugLogReadEnabled = true,
        private readonly SensitiveDataProcessor $logSanitizer = new SensitiveDataProcessor()
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
                return $this->readDebugLog($serverRequest);
            }

            $response = [
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
                    'server_software' => $serverRequest->getServerParams()['SERVER_SOFTWARE'] ?? null,
                    'server_name' => $serverRequest->getServerParams()['SERVER_NAME'] ?? null,
                    // Always null: the internal address has no diagnostic value. The key stays until 4.0 for parsers.
                    'server_addr' => null,
                    'database_version' => $this->databaseVersion,
                    'extra_variables' => $shopExtraVariables,
                ],
                'shop_configuration' => $this->configurationManager->returnConfigurationOptions(),
            ];

            if ($this->shopEnvironmentReportProvider !== null) {
                // Full backend shop-environment report, on-demand for the API selector knowledge base.
                $response['shop_environment'] = ($this->shopEnvironmentReportProvider)();
            }

            return $response;
        }

        $rejectedOptions = $this->configurationManager->updateConfigurationOptions(parent::processRequest($serverRequest, $endpointName));

        $this->configurationManager->persist();

        if ($rejectedOptions !== []) {
            // A constraint refused these values; the rest of the batch was stored.
            $this->debugLogger->logEvent('Remote configuration values rejected.', ['options' => $rejectedOptions], 'CONFIG_REJECTED');
        }

        return null;
    }

    /**
     * Returns the last debug-log lines with sensitive values masked (lines written before a log processor was
     * registered still hold them), or a refusal when the host disabled remote reads. Every read is itself logged.
     *
     * @return array{debug_log: string|null, reason?: string}
     */
    private function readDebugLog(ServerRequestInterface $serverRequest): array
    {
        $this->debugLogger->logEvent(
            $this->debugLogReadEnabled ? 'Debug log read remotely.' : 'Remote debug log read refused (disabled).',
            ['client_ip' => $serverRequest->getServerParams()['REMOTE_ADDR'] ?? null, 'lines' => $this->debugLogNumLines],
            'DEBUG_LOG_READ'
        );

        if (!$this->debugLogReadEnabled) {
            return ['debug_log' => null, 'reason' => 'disabled'];
        }

        return ['debug_log' => $this->logSanitizer->sanitizeText($this->debugLogger->getDebugLog($this->debugLogNumLines))];
    }
}
