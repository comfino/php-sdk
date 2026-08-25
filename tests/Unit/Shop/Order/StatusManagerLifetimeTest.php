<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order;

use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Shop\Order\StatusManager;
use PHPUnit\Framework\TestCase;

/**
 * What the scope cache keeps, and how a host can tell.
 *
 * The cache is `??=`: the **first** adapter given for a scope wins, every later one is silently ignored, and nothing
 * is ever evicted. That is free for a plugin — one shop, one adapter, one request, a process gone — and it is a trap
 * for a long-lived host, where an adapter built per request keeps its collaborators alive and serves the *next* request
 * for that merchant. The 3.1 answer is two-part: say so (the docblocks), and make it observable (these methods), so a
 * host that must use the cache can prove in a test that it releases what it registers.
 */
final class StatusManagerLifetimeTest extends TestCase
{
    protected function tearDown(): void
    {
        StatusManager::reset();
        ConfigurationManager::reset();

        parent::tearDown();
    }

    public function testTheCacheKeepsTheFirstAdapterForAScope(): void
    {
        $first = new CountingStatusAdapter();
        $second = new CountingStatusAdapter();

        StatusManager::getInstance($first, 'shop-a');
        StatusManager::getInstance($second, 'shop-a')->setOrderStatus('1', 'ACCEPTED');

        self::assertSame([['1', 'ACCEPTED']], $first->applied);
        self::assertSame([], $second->applied, 'The documented trap: the later adapter is silently discarded.');
    }

    /**
     * The way out for a host that builds an adapter per request: do not involve the cache at all.
     */
    public function testCreateDoesNotTouchTheCache(): void
    {
        $adapter = new CountingStatusAdapter();

        StatusManager::create($adapter, 'shop-a')->setOrderStatus('1', 'ACCEPTED');

        self::assertSame([['1', 'ACCEPTED']], $adapter->applied);
        self::assertSame(0, StatusManager::scopeCount(), 'Nothing was stored, so there is nothing to release.');
    }

    public function testEachCreatedInstanceIsIndependent(): void
    {
        $first = new CountingStatusAdapter();
        $second = new CountingStatusAdapter();

        StatusManager::create($first, 'shop-a')->setOrderStatus('1', 'ACCEPTED');
        StatusManager::create($second, 'shop-a')->setOrderStatus('2', 'REJECTED');

        self::assertSame([['1', 'ACCEPTED']], $first->applied);
        self::assertSame([['2', 'REJECTED']], $second->applied);
    }

    public function testCreatedInstancesStillReportTheirScope(): void
    {
        self::assertSame('shop-a', StatusManager::create(new CountingStatusAdapter(), 'shop-a')->getScope());
    }

    /**
     * The assertion a multi-tenant host puts at the end of its request lifecycle test. Without it, a forgotten
     * `reset()` is invisible until a long-running process has accumulated one pinned object graph per merchant.
     */
    public function testTheHeldScopesAreObservable(): void
    {
        StatusManager::getInstance(new CountingStatusAdapter(), 'shop-a');
        StatusManager::getInstance(new CountingStatusAdapter(), 'shop-b');

        self::assertSame(['shop-a', 'shop-b'], StatusManager::scopes());
        self::assertSame(2, StatusManager::scopeCount());

        StatusManager::reset('shop-a');

        self::assertSame(['shop-b'], StatusManager::scopes());

        StatusManager::reset();

        self::assertSame([], StatusManager::scopes());
        self::assertSame(0, StatusManager::scopeCount());
    }

    /**
     * The same `??=` shape, and now the same diagnostics, on the other scope-addressed classes. One of them is checked
     * here because the retention is a property of the pattern rather than of `StatusManager`.
     */
    public function testTheOtherScopeAddressedClassesReportTheirScopesToo(): void
    {
        self::assertSame([], ConfigurationManager::scopes());
        self::assertSame(0, ConfigurationManager::scopeCount());
    }
}
