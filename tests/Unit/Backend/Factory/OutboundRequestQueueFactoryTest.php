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

use Comfino\Api\ClientInterface;
use Comfino\Api\Serializer\Json;
use Comfino\Backend\Factory\OutboundRequestQueueFactory;
use Comfino\Backend\Queue\CancelOrderHandler;
use Comfino\Backend\Queue\ReportErrorHandler;
use Comfino\Tests\Unit\Backend\Queue\InMemoryRetryQueueStorage;
use PHPUnit\Framework\TestCase;

final class OutboundRequestQueueFactoryTest extends TestCase
{
    public function testCreateRegistersCancelOrderHandler(): void
    {
        $queue = (new OutboundRequestQueueFactory())->create(
            storage: new InMemoryRetryQueueStorage(),
            minimalTimeoutClient: $this->createMock(ClientInterface::class),
        );

        /* Calling enqueue() validates that a handler is registered for the operation type. InvalidArgumentException is
           thrown when the handler is absent — if no exception is raised, the handler was registered by the factory. */
        $queue->enqueue(CancelOrderHandler::OPERATION_TYPE, ['orderId' => 'ORD-1']);

        self::assertSame(1, $queue->pendingCount());
    }

    public function testCreateRegistersReportErrorHandler(): void
    {
        $queue = (new OutboundRequestQueueFactory())->create(
            storage: new InMemoryRetryQueueStorage(),
            minimalTimeoutClient: $this->createMock(ClientInterface::class),
        );

        $queue->enqueue(ReportErrorHandler::OPERATION_TYPE, [
            'host' => 'shop.example.com',
            'platform' => 'TestPlatform',
            'environment' => (new Json())->serialize(['php_version' => '8.2']),
            'errorCode' => 'E_ERROR',
            'errorMessage' => 'Test error',
            'apiRequestUrl' => '',
            'apiRequest' => '',
            'apiResponse' => '',
            'stackTrace' => '',
        ]);

        self::assertSame(1, $queue->pendingCount());
    }

    public function testCreateRegistersBothHandlersTogether(): void
    {
        $queue = (new OutboundRequestQueueFactory())->create(
            storage: new InMemoryRetryQueueStorage(),
            minimalTimeoutClient: $this->createMock(ClientInterface::class),
        );

        $queue->enqueue(CancelOrderHandler::OPERATION_TYPE, ['orderId' => 'ORD-1']);
        $queue->enqueue(ReportErrorHandler::OPERATION_TYPE, [
            'host' => 'shop.example.com',
            'platform' => 'TestPlatform',
            'environment' => (new Json())->serialize([]),
            'errorCode' => 'E_ERROR',
            'errorMessage' => 'Test error',
            'apiRequestUrl' => '',
            'apiRequest' => '',
            'apiResponse' => '',
            'stackTrace' => '',
        ]);

        self::assertSame(2, $queue->pendingCount());
    }

    public function testTenantClientFactoryIsUsedToDeliverReportsPerTenant(): void
    {
        /* The wiring a multi-tenant host needs: without the factory the drain would deliver tenant-b's report with the
           ambient cron-scope client's key. */
        $ambientClient = $this->createMock(ClientInterface::class);
        $ambientClient->expects($this->never())->method('sendLoggedError');

        $tenantClient = $this->createMock(ClientInterface::class);
        $tenantClient->expects($this->once())->method('sendLoggedError');

        $seenTenants = [];

        $queue = (new OutboundRequestQueueFactory())->create(
            storage: new InMemoryRetryQueueStorage(),
            minimalTimeoutClient: $ambientClient,
            tenantClientFactory: static function (?string $tenantKey) use (&$seenTenants, $tenantClient): ClientInterface {
                $seenTenants[] = $tenantKey;

                return $tenantClient;
            },
        );

        $queue->enqueue(ReportErrorHandler::OPERATION_TYPE, $this->reportPayload(), 'tenant-b');

        $result = $queue->process(10);

        self::assertSame(1, $result->processed);
        self::assertSame(['tenant-b'], $seenTenants);
    }

    public function testCancelOrderKeepsTheAmbientClientWhenATenantFactoryIsGiven(): void
    {
        /* cancel_order's payload is platform-shaped, so its tenant-aware handler stays a platform concern; the factory
           parameter must not silently change how cancel_order is delivered. */
        $ambientClient = $this->createMock(ClientInterface::class);
        $ambientClient->expects($this->once())->method('cancelOrder')->with('ORD-1');

        $tenantClient = $this->createMock(ClientInterface::class);
        $tenantClient->expects($this->never())->method('cancelOrder');

        $queue = (new OutboundRequestQueueFactory())->create(
            storage: new InMemoryRetryQueueStorage(),
            minimalTimeoutClient: $ambientClient,
            tenantClientFactory: static fn (?string $tenantKey): ClientInterface => $tenantClient,
        );

        $queue->enqueue(CancelOrderHandler::OPERATION_TYPE, ['orderId' => 'ORD-1'], 'tenant-b');

        self::assertSame(1, $queue->process(10)->processed);
    }

    /**
     * @return array<string, scalar>
     */
    private function reportPayload(): array
    {
        return [
            'host' => 'shop.example.com',
            'platform' => 'TestPlatform',
            'environment' => (new Json())->serialize(['php_version' => '8.2']),
            'errorCode' => 'E_ERROR',
            'errorMessage' => 'Test error',
            'apiRequestUrl' => '',
            'apiRequest' => '',
            'apiResponse' => '',
            'stackTrace' => '',
        ];
    }

    public function testCreateAcceptsCustomSerializer(): void
    {
        $serializer = new Json();

        $queue = (new OutboundRequestQueueFactory())->create(
            storage: new InMemoryRetryQueueStorage(),
            minimalTimeoutClient: $this->createMock(ClientInterface::class),
            serializer: $serializer,
        );

        $queue->enqueue(ReportErrorHandler::OPERATION_TYPE, [
            'host' => 'shop.example.com',
            'platform' => 'TestPlatform',
            'environment' => $serializer->serialize([]),
            'errorCode' => '500',
            'errorMessage' => 'Server error',
            'apiRequestUrl' => '',
            'apiRequest' => '',
            'apiResponse' => '',
            'stackTrace' => '',
        ]);

        self::assertSame(1, $queue->pendingCount());
    }
}
