<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Factory
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
