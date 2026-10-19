<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Factory
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Factory;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Api\CircuitBreaker\CircuitBreakerInterface;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Factory\ApiClientFactory;
use Comfino\Backend\Health\ApiHealthRecorder;
use Comfino\Backend\Queue\ApiTransientErrorClassifier;
use Comfino\Backend\Queue\QueueErrorDisposition;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

/**
 * The credential-bound client built by the factory carries the circuit breaker, rate limiter, request observer and
 * tenant key through the api-client's own setters.
 */
final class ApiClientFactoryResilienceTest extends TestCase
{
    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testOpenBreakerMakesTheFactoryClientFailFast(): void
    {
        $transport = new RecordingHttpClient();
        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $breaker->method('isOpen')->willReturn(true);

        $client = (new ApiClientFactory())->createClient($transport, new Psr17Factory(), new Psr17Factory(), 'key', circuitBreaker: $breaker);

        try {
            $client->getWidgetKey();
            $this->fail('The call must fail fast.');
        } catch (ServiceUnavailable $e) {
            // A queued operation rejected this way waits for a later drain instead of being dropped.
            $this->assertSame(QueueErrorDisposition::Retry, (new ApiTransientErrorClassifier())->classify('cancel_order', $e));
        }

        $this->assertSame([], $transport->requests);
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testTenantKeyReachesTheBreakerKey(): void
    {
        $psr17 = new Psr17Factory();
        $transport = new RecordingHttpClient([
            $psr17->createResponse(200)->withHeader('Content-Type', 'application/json')->withBody($psr17->createStream('"k"')),
        ]);

        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $breaker->expects($this->once())->method('isOpen')->with('store_7|api-ecommerce.comfino.pl')->willReturn(false);

        (new ApiClientFactory())->createClient($transport, $psr17, $psr17, 'key', circuitBreaker: $breaker, tenantKey: 'store_7')
            ->getWidgetKey();
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testHealthRecorderObservesFactoryClientCalls(): void
    {
        $psr17 = new Psr17Factory();
        $transport = new RecordingHttpClient([
            $psr17->createResponse(200)->withHeader('Content-Type', 'application/json')->withBody($psr17->createStream('"k"')),
        ]);
        $recorder = new ApiHealthRecorder(new ScopedCache(new ArrayCachePool()));

        (new ApiClientFactory())->createClient($transport, $psr17, $psr17, 'key', requestObserver: $recorder, tenantKey: 'store_2')
            ->getWidgetKey();

        $this->assertNotNull($recorder->getHealth('store_2')->lastSuccessAt);
        $this->assertNull($recorder->getHealth('store_1')->lastSuccessAt);
    }

    public function testShopperPathBudgetIsAFewSeconds(): void
    {
        $this->assertLessThanOrEqual(5, ApiClientFactory::SHOPPER_PATH_MAX_TOTAL_TRANSFER_TIMEOUT);
    }
}
