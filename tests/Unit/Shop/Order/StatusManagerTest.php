<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order;

use Comfino\Enum\OrderStatus;
use Comfino\Shop\Order\StatusAdapterInterface;
use Comfino\Shop\Order\StatusManager;
use PHPUnit\Framework\TestCase;

final class StatusManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        StatusManager::reset();
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
