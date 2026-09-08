<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Cache
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Cache;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Cache\ScopedCache;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;

final class ScopedCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        CacheManager::reset();

        parent::tearDown();
    }

    public function testScopedKeysDoNotCollideBetweenTenants(): void
    {
        $pool = new ArrayCachePool();

        $shopOne = new ScopedCache($pool, 'shop_1');
        $shopTwo = new ScopedCache($pool, 'shop_2');

        $shopOne->set('product_types.paywall.pl', ['A' => 'A']);
        $shopTwo->set('product_types.paywall.pl', ['B' => 'B']);

        // The quietest bug this class exists to prevent: nothing fails, the wrong answer is simply fast.
        self::assertSame(['A' => 'A'], $shopOne->get('product_types.paywall.pl'));
        self::assertSame(['B' => 'B'], $shopTwo->get('product_types.paywall.pl'));
    }

    public function testUnscopedViewLeavesKeysUntouched(): void
    {
        $cache = new ScopedCache(new ArrayCachePool());

        self::assertSame('plain_key', $cache->scopedKey('plain_key'));
    }

    /**
     * PSR-6 reserves `{}()/\@:` in keys, and a tenant identifier taken from a URL or a shop code routinely contains
     * one of them.
     */
    public function testScopedKeyReplacesCharactersPsr6Reserves(): void
    {
        $cache = new ScopedCache(new ArrayCachePool(), 'shop:1/eu');

        self::assertSame('shop_1_eu.creditors', $cache->scopedKey('creditors'));
    }

    public function testGetReturnsTheDefaultOnAMiss(): void
    {
        $cache = new ScopedCache(new ArrayCachePool(), 'shop_1');

        self::assertSame('fallback', $cache->get('absent', 'fallback'));
    }

    /**
     * A cache is an optimization. An integration that dies because a cache backend is briefly unavailable is strictly
     * worse than one that runs slowly, so a throwing pool must surface as a miss.
     */
    public function testGetReturnsTheDefaultWhenThePoolThrows(): void
    {
        $cache = new ScopedCache($this->throwingPool(), 'shop_1');

        self::assertSame('fallback', $cache->get('anything', 'fallback'));
    }

    public function testSetSwallowsAThrowingPool(): void
    {
        $cache = new ScopedCache($this->throwingPool(), 'shop_1');

        // The writing cannot land, but it must not propagate either - the caller's work continues uncached.
        $cache->set('anything', 'value');

        self::assertSame('fallback', $cache->get('anything', 'fallback'));
    }

    public function testDeleteRemovesOnlyTheScopedEntry(): void
    {
        $pool = new ArrayCachePool();

        $shopOne = new ScopedCache($pool, 'shop_1');
        $shopTwo = new ScopedCache($pool, 'shop_2');

        $shopOne->set('creditors', ['one']);
        $shopTwo->set('creditors', ['two']);

        $shopOne->delete('creditors');

        self::assertNull($shopOne->get('creditors'));
        self::assertSame(['two'], $shopTwo->get('creditors'));
    }

    public function testWithScopeReturnsTheSameInstanceForTheSameScope(): void
    {
        $cache = new ScopedCache(new ArrayCachePool(), 'shop_1');

        self::assertSame($cache, $cache->withScope('shop_1'));
        self::assertNotSame($cache, $cache->withScope('shop_2'));
    }

    public function testCacheManagerForScopeViewsTheInitializedPool(): void
    {
        $pool = new ArrayCachePool();

        CacheManager::init($pool);

        $shopOne = CacheManager::forScope('shop_1');
        $shopOne->set('widget_types.pl', ['W']);

        self::assertSame($pool, $shopOne->getPool());
        self::assertSame('shop_1', $shopOne->getScope());
        self::assertNull(CacheManager::forScope('shop_2')->get('widget_types.pl'));
    }

    public function testCacheManagerForScopeThrowsWhenNotInitialized(): void
    {
        $this->expectException(LogicException::class);

        CacheManager::forScope('shop_1');
    }

    public function testCacheManagerForPoolNeedsNoProcessState(): void
    {
        $cache = CacheManager::forPool(new ArrayCachePool(), 'shop_1');

        $cache->set('creditors', ['one']);

        self::assertSame(['one'], $cache->get('creditors'));
    }

    /**
     * The static accessors keep working for the single-shop plugins that rely on them, reading and writing the scope
     * that init() fixed.
     */
    public function testStaticAccessorsUseTheInitializedScope(): void
    {
        $pool = new ArrayCachePool();

        CacheManager::init($pool, 'shop_1');
        CacheManager::set('creditors', ['one']);

        self::assertSame(['one'], CacheManager::get('creditors'));
        self::assertSame(['one'], CacheManager::forScope('shop_1')->get('creditors'));
        self::assertNull(CacheManager::forScope('shop_2')->get('creditors'));
    }

    private function throwingPool(): CacheItemPoolInterface&MockObject
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new RuntimeException('cache backend unavailable'));

        return $pool;
    }
}
