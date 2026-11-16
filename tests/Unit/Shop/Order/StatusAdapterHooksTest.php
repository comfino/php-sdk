<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order;

use Comfino\Enum\OrderStatus;
use Comfino\Shop\Order\StatusAdapterInterface;
use Comfino\Shop\Order\StatusApplicationContext;
use Comfino\Shop\Order\StatusManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StatusAdapterHooksTest extends TestCase
{
    public function testDefaultHooksKeepExistingAdaptersUnchanged(): void
    {
        $adapter = new RecordingStatusAdapter([], [], ['ACCEPTED' => 'accepted']);

        $adapter->setStatus('1', 'accepted');

        $this->assertCount(1, $adapter->applied);
    }

    public function testOrderNotOwnedByTheIntegrationIsAcknowledgedAndDropped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with($this->stringContains('not owned'), ['orderId' => '7', 'status' => 'ACCEPTED']);

        $adapter = $this->adapter(ownedOrders: ['1'], currentStatuses: [], logger: $logger);

        $adapter->setStatus('7', 'ACCEPTED');
        $adapter->setStatus('1', 'ACCEPTED');

        $this->assertSame(['1'], array_column($adapter->applied, 'orderId'));
    }

    public function testRedeliveredStatusIsANoOp(): void
    {
        $adapter = $this->adapter(ownedOrders: ['order-1'], currentStatuses: ['order-1' => 'accepted']);

        $adapter->setStatus('order-1', 'ACCEPTED');
        $adapter->setStatus('order-1', 'REJECTED');

        $this->assertSame(['rejected'], array_column($adapter->applied, 'platformStatusCode'));
    }

    public function testCancelledByShopIsForbiddenByDefault(): void
    {
        $this->assertContains(OrderStatus::CANCELLED_BY_SHOP, StatusManager::DEFAULT_FORBIDDEN_STATUSES);

        $forbidden = array_map(static fn (OrderStatus $status): string => $status->value, StatusManager::DEFAULT_FORBIDDEN_STATUSES);
        $adapter = new RecordingStatusAdapter([], $forbidden, ['CANCELLED_BY_SHOP' => 'cancelled_by_shop']);

        $adapter->setStatus('1', 'CANCELLED_BY_SHOP');

        $this->assertSame([], $adapter->applied);
    }

    /**
     * The context is entered by StatusManager, not only by AbstractStatusAdapter, so an adapter implementing the
     * interface directly still lets the platform's cancel hook recognize a webhook-applied status.
     */
    public function testStatusManagerActivatesTheApplicationContextForAnyAdapter(): void
    {
        $adapter = new class () implements StatusAdapterInterface {
            /** @var bool[] */
            public array $contextActive = [];

            public function setStatus(string $orderId, string $status): void
            {
                $this->contextActive[] = StatusApplicationContext::isActive('store_5');
            }
        };

        StatusManager::create($adapter, 'store_5')->setOrderStatus('1', 'CANCELLED');

        $this->assertSame([true], $adapter->contextActive);
        $this->assertFalse(StatusApplicationContext::isActive('store_5'), 'The context ends with the call.');
    }

    /**
     * @param string[] $ownedOrders
     * @param array<string, string> $currentStatuses
     */
    private function adapter(array $ownedOrders, array $currentStatuses, ?LoggerInterface $logger = null): HookedStatusAdapter
    {
        return new HookedStatusAdapter($ownedOrders, $currentStatuses, $logger);
    }
}
