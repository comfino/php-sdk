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

final class StatusManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        StatusManager::reset();
        StatusApplicationContext::reset();
    }

    /**
     * The most dangerous singleton in the SDK: the first tenant to ask for a manager owned the adapter, so every later
     * tenant's status notification was applied to the *first* tenant's orders. Hosts worked around it by calling
     * reset() before every webhook - which only works as long as nobody forgets.
     */
    public function testEachScopeRoutesToItsOwnAdapter(): void
    {
        $shopAAdapter = $this->createMock(StatusAdapterInterface::class);
        $shopBAdapter = $this->createMock(StatusAdapterInterface::class);

        $shopAAdapter->expects($this->never())->method('setStatus');
        $shopBAdapter->expects($this->once())->method('setStatus')->with('ORDER-42', OrderStatus::ACCEPTED->value);

        StatusManager::getInstance($shopAAdapter, 'shop_a');
        StatusManager::getInstance($shopBAdapter, 'shop_b')
            ->setOrderStatus('ORDER-42', OrderStatus::ACCEPTED->value);
    }

    public function testGetInstanceReturnsTheSameInstancePerScope(): void
    {
        $adapter = $this->createMock(StatusAdapterInterface::class);

        $this->assertSame(StatusManager::getInstance($adapter, 'shop_a'), StatusManager::getInstance($adapter, 'shop_a'));
        $this->assertSame('shop_a', StatusManager::getInstance($adapter, 'shop_a')->getScope());
    }

    public function testResetDropsOnlyTheNamedScope(): void
    {
        $adapter = $this->createMock(StatusAdapterInterface::class);

        $shopA = StatusManager::getInstance($adapter, 'shop_a');
        $shopB = StatusManager::getInstance($adapter, 'shop_b');

        StatusManager::reset('shop_a');

        $this->assertNotSame($shopA, StatusManager::getInstance($adapter, 'shop_a'));
        $this->assertSame($shopB, StatusManager::getInstance($adapter, 'shop_b'));
    }

    public function testTheApplicationContextFollowsTheManagersScope(): void
    {
        $adapter = $this->createMock(StatusAdapterInterface::class);
        $context = StatusManager::getInstance($adapter, 'shop_a')->applicationContext();

        $this->assertSame(StatusApplicationContext::forScope('shop_a'), $context);
        $this->assertSame('shop_a', $context->getScope());
    }

    public function testGetInstanceReturnsSameInstance(): void
    {
        $adapter = $this->createMock(StatusAdapterInterface::class);

        $instance1 = StatusManager::getInstance($adapter);
        $instance2 = StatusManager::getInstance($adapter);

        $this->assertSame($instance1, $instance2);
    }

    public function testSetOrderStatusDelegatesToAdapter(): void
    {
        $adapter = $this->createMock(StatusAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('setStatus')
            ->with('ORDER-42', OrderStatus::ACCEPTED->value);

        $manager = StatusManager::getInstance($adapter);
        $manager->setOrderStatus('ORDER-42', OrderStatus::ACCEPTED->value);
    }

    public function testResetClearsSingletonInstance(): void
    {
        $adapter1 = $this->createMock(StatusAdapterInterface::class);
        $instance1 = StatusManager::getInstance($adapter1);

        StatusManager::reset();

        $adapter2 = $this->createMock(StatusAdapterInterface::class);
        $instance2 = StatusManager::getInstance($adapter2);

        $this->assertNotSame($instance1, $instance2);
    }

    public function testDefaultIgnoredStatusesAreDefined(): void
    {
        $this->assertContains(OrderStatus::WAITING_FOR_FILLING, StatusManager::DEFAULT_IGNORED_STATUSES);
        $this->assertContains(OrderStatus::WAITING_FOR_CONFIRMATION, StatusManager::DEFAULT_IGNORED_STATUSES);
        $this->assertContains(OrderStatus::WAITING_FOR_PAYMENT, StatusManager::DEFAULT_IGNORED_STATUSES);
        $this->assertContains(OrderStatus::PAID, StatusManager::DEFAULT_IGNORED_STATUSES);
    }

    public function testDefaultForbiddenStatusesAreDefined(): void
    {
        $this->assertContains(OrderStatus::RESIGN, StatusManager::DEFAULT_FORBIDDEN_STATUSES);
    }
}
