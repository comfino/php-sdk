<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Cache
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Cache;

use Comfino\Backend\Cache\CacheManager;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class CacheManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CacheManager::reset();
    }

    protected function tearDown(): void
    {
        CacheManager::reset();

        parent::tearDown();
    }

    private function mockPool(): CacheItemPoolInterface&MockObject
    {
        return $this->createMock(CacheItemPoolInterface::class);
    }

    private function mockItem(mixed $value = null, bool $isHit = false): CacheItemInterface&MockObject
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn($isHit);
        $item->method('get')->willReturn($value);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();

        return $item;
    }

    public function testGetPoolThrowsWhenNotInitialized(): void
    {
        $this->expectException(LogicException::class);

        CacheManager::getPool();
    }

    public function testInitStoresPool(): void
    {
        $pool = $this->mockPool();
        CacheManager::init($pool);

        $this->assertSame($pool, CacheManager::getPool());
    }

    public function testResetClearsPool(): void
    {
        CacheManager::init($this->mockPool());
        CacheManager::reset();

        $this->expectException(LogicException::class);

        CacheManager::getPool();
    }

    public function testGetReturnsCachedValueOnHit(): void
    {
        $item = $this->mockItem('hello', true);
        $pool = $this->mockPool();
        $pool->method('getItem')->with('my-key')->willReturn($item);

        CacheManager::init($pool);

        $this->assertSame('hello', CacheManager::get('my-key', 'default'));
    }

    public function testGetReturnsDefaultOnMiss(): void
    {
        $item = $this->mockItem(null, false);
        $pool = $this->mockPool();
        $pool->method('getItem')->willReturn($item);

        CacheManager::init($pool);

        $this->assertSame('fallback', CacheManager::get('missing', 'fallback'));
    }

    public function testGetReturnsNullDefaultOnMissWhenNoDefaultGiven(): void
    {
        $item = $this->mockItem(null, false);
        $pool = $this->mockPool();
        $pool->method('getItem')->willReturn($item);

        CacheManager::init($pool);

        $this->assertNull(CacheManager::get('missing'));
    }

    public function testSetSavesItemToPool(): void
    {
        $item = $this->mockItem();
        $pool = $this->mockPool();
        $pool->method('getItem')->willReturn($item);
        $pool->expects($this->once())->method('save')->with($item);

        CacheManager::init($pool);
        CacheManager::set('key', 'value');
    }

    public function testSetWithTtlCallsExpiresAfter(): void
    {
        $item = $this->mockItem();
        $item->expects($this->once())->method('expiresAfter')->with(300)->willReturnSelf();

        $pool = $this->mockPool();
        $pool->method('getItem')->willReturn($item);
        $pool->method('save');

        CacheManager::init($pool);
        CacheManager::set('key', 'value', 300);
    }

    public function testSetWithZeroTtlDoesNotCallExpiresAfter(): void
    {
        $item = $this->mockItem();
        $item->expects($this->never())->method('expiresAfter');

        $pool = $this->mockPool();
        $pool->method('getItem')->willReturn($item);
        $pool->method('save');

        CacheManager::init($pool);
        CacheManager::set('key', 'value', 0);
    }

    public function testSetSilentlyIgnoresPoolExceptions(): void
    {
        $pool = $this->mockPool();
        $pool->method('getItem')->willThrowException(new \RuntimeException('Pool error'));

        CacheManager::init($pool);

        /* Should not throw — exceptions from the pool are swallowed after retries. */
        CacheManager::set('key', 'value');

        $this->addToAssertionCount(1);
    }
}
