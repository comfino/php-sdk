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

use Cache\TagInterop\TaggableCacheItemPoolInterface;
use Comfino\Backend\Webhook\WebhookEndpoint;
use Comfino\Enum\CacheItemType;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

class CacheInvalidate extends WebhookEndpoint
{
    public function __construct(
        string $name,
        string $endpointUrl,
        private readonly TaggableCacheItemPoolInterface $cache
    ) {
        parent::__construct($name, $endpointUrl);

        $this->methods = ['POST', 'PUT', 'PATCH'];
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws InvalidArgumentException
     */
    public function processRequest(ServerRequestInterface $serverRequest, ?string $endpointName = null): ?array
    {
        $allowedTags = array_column(CacheItemType::cases(), 'value');

        $this->cache->invalidateTags(
            array_intersect(
                parent::processRequest($serverRequest, $endpointName),
                $allowedTags
            )
        );

        return null;
    }
}
