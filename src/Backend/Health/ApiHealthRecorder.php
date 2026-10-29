<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Health
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Health;

use Comfino\Api\ApiContext;
use Comfino\Api\RequestObserverInterface;
use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Clock\SystemClock;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Records per-tenant API health - last success, last failure, consecutive failures - in a shared cache, so an admin
 * panel can show whether the integration currently reaches ComfinoPay.
 *
 * Wire it on either client path: {@see \Comfino\Backend\Factory\ApiClientFactory::createClient()}'s $transportObserver
 * (legacy, credential-bound client) or createSharedClient()'s $requestObserver. A transport failure or a 5xx response
 * is a failure; any other response is a success. Writes are best-effort and never throw into the request.
 */
final class ApiHealthRecorder implements RequestObserverInterface
{
    private const CACHE_KEY_PREFIX = 'health.api.';

    public function __construct(private readonly ScopedCache $cache, private readonly ClockInterface $clock = new SystemClock())
    {
    }

    public function getHealth(?string $tenantKey = null): ApiHealth
    {
        $data = $this->cache->get(self::cacheKey($tenantKey));

        if (!is_array($data)) {
            return new ApiHealth();
        }

        return new ApiHealth(
            isset($data['lastSuccessAt']) ? (int) $data['lastSuccessAt'] : null,
            isset($data['lastFailureAt']) ? (int) $data['lastFailureAt'] : null,
            (int) ($data['consecutiveFailures'] ?? 0),
            isset($data['lastFailureClass']) ? (string) $data['lastFailureClass'] : null
        );
    }

    public function onRequest(ApiContext $context, RequestInterface $request, int $attempt): void
    {
    }

    public function onResponse(ApiContext $context, RequestInterface $request, ResponseInterface $response, float $durationMs): void
    {
        $this->recordResponse($response, $context->tenantKey);
    }

    public function onFailure(ApiContext $context, RequestInterface $request, Throwable $error, int $attempt): void
    {
        $this->recordFailure(get_class($error), $context->tenantKey);
    }

    private function recordResponse(ResponseInterface $response, ?string $tenantKey): void
    {
        if ($response->getStatusCode() >= 500) {
            $this->recordFailure('HTTP ' . $response->getStatusCode(), $tenantKey);

            return;
        }

        $health = $this->getHealth($tenantKey);

        $this->store($tenantKey, new ApiHealth($this->clock->now(), $health->lastFailureAt, 0, $health->lastFailureClass));
    }

    private function recordFailure(string $failureClass, ?string $tenantKey): void
    {
        $health = $this->getHealth($tenantKey);

        $this->store($tenantKey, new ApiHealth($health->lastSuccessAt, $this->clock->now(), $health->consecutiveFailures + 1, $failureClass));
    }

    private function store(?string $tenantKey, ApiHealth $health): void
    {
        try {
            $this->cache->set(self::cacheKey($tenantKey), [
                'lastSuccessAt' => $health->lastSuccessAt,
                'lastFailureAt' => $health->lastFailureAt,
                'consecutiveFailures' => $health->consecutiveFailures,
                'lastFailureClass' => $health->lastFailureClass,
            ]);
        } catch (Throwable) {
            // Health recording is best-effort.
        }
    }

    private static function cacheKey(?string $tenantKey): string
    {
        return self::CACHE_KEY_PREFIX . sha1($tenantKey ?? '');
    }
}
