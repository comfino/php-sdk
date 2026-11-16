<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Queue;

use Comfino\Api\ClientInterface;
use Comfino\Backend\Queue\CancelOrderHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

final class CancelOrderHandlerTest extends TestCase
{
    /**
     * @throws Throwable
     */
    public function testCancelIsSentWithoutAGuard(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('cancelOrder')->with('42');

        (new CancelOrderHandler($client))->execute(['orderId' => '42']);
    }

    /**
     * @throws Throwable
     */
    public function testCancelForAnOrderClosedAtComfinoPayIsANoOp(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('cancelOrder');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with($this->anything(), ['orderId' => '42', 'guard' => 'terminal']);

        $seen = [];

        $handler = new CancelOrderHandler(
            $client,
            static function (string $orderId, array $payload) use (&$seen): bool {
                $seen[] = [$orderId, $payload];

                return true;
            },
            $logger
        );

        $handler->execute(['orderId' => '42', 'storeId' => 3]);

        $this->assertSame([['42', ['orderId' => '42', 'storeId' => 3]]], $seen);
    }

    /**
     * @throws Throwable
     */
    public function testCancelForAnOpenOrderIsSentExactlyOnce(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())->method('cancelOrder')->with('7');

        (new CancelOrderHandler($client, static fn (): bool => false))->execute(['orderId' => '7']);
    }

    /**
     * @throws Throwable
     */
    public function testEmptyOrderIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CancelOrderHandler($this->createMock(ClientInterface::class)))->execute([]);
    }
}
