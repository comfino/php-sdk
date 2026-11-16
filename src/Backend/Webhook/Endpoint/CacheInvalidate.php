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

use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Webhook\WebhookEndpoint;
use Comfino\Enum\CacheItemType;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class CacheInvalidate extends WebhookEndpoint
{
    /**
     * @param string $scope The tenant whose cache entries are invalidated; must match the scope of the
     *                      {@see ScopedCache} the entries were written through. Empty for a single-tenant host.
     */
    public function __construct(
        string $name,
        string $endpointUrl,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $scope = ''
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

        if ($this->cache instanceof TagAwareCacheInterface) {
            $tags = array_intersect(parent::processRequest($serverRequest, $endpointName), $allowedTags);

            if ($this->scope !== '') {
                $tags = array_map((new ScopedCache($this->cache, $this->scope))->scopedTag(...), $tags);
            }

            $this->cache->invalidateTags($tags);
        }

        return null;
    }
}
