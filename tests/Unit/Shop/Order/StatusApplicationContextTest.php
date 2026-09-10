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

use Comfino\Shop\Order\StatusApplicationContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StatusApplicationContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StatusApplicationContext::reset();
    }

    protected function tearDown(): void
    {
        StatusApplicationContext::reset();

        parent::tearDown();
    }

    public function testTheContextIsInactiveUntilEntered(): void
    {
        self::assertFalse(StatusApplicationContext::forScope('shop_a')->isActiveInScope());
        self::assertFalse(StatusApplicationContext::isActive());
    }

    public function testTheContextIsActiveInsideApply(): void
    {
        $context = StatusApplicationContext::forScope('shop_a');

        $seen = $context->apply(static fn (): bool => $context->isActiveInScope());

        self::assertTrue($seen);
        self::assertFalse($context->isActiveInScope(), 'The context must be exited when the callback returns.');
    }

    public function testTheContextIsExitedEvenWhenTheCallbackThrows(): void
    {
        $context = StatusApplicationContext::forScope('shop_a');

        try {
            $context->apply(static function (): void {
                throw new RuntimeException('platform write failed');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        self::assertSame(0, $context->getDepth());
    }

    /**
     * A depth counter rather than a plain flag, so the context stays correct if status application nests.
     */
    public function testNestedApplicationKeepsTheContextActiveUntilTheOutermostExits(): void
    {
        $context = StatusApplicationContext::forScope('shop_a');

        $context->apply(static function () use ($context): void {
            $context->apply(static function () use ($context): void {
                self::assertSame(2, $context->getDepth());
            });

            self::assertTrue($context->isActiveInScope(), 'The outer application is still in progress.');
        });

        self::assertFalse($context->isActiveInScope());
    }

    /**
     * The defect this scoping fixes: while tenant A's status change was being applied, a genuine and unrelated status
     * change for tenant B saw an active context and was suppressed. Tenant A's write swallowed tenant B's event.
     */
    public function testOneTenantsApplicationDoesNotSuppressAnother(): void
    {
        $shopA = StatusApplicationContext::forScope('shop_a');

        $shopA->apply(static function (): void {
            self::assertTrue(StatusApplicationContext::isActive('shop_a'));
            self::assertFalse(StatusApplicationContext::isActive('shop_b'), 'Shop B is not applying anything, so its hooks must react normally.');
        });
    }

    /**
     * A hook that does not know its tenant gets the tenant-blind answer, which is what this class did before it had
     * scopes - so an integration that has not been updated behaves exactly as it used to.
     */
    public function testTheTenantBlindQueryStillAnswersForAnyActiveScope(): void
    {
        StatusApplicationContext::forScope('shop_a')->apply(static function (): void {
            self::assertTrue(StatusApplicationContext::isActive());
        });

        self::assertFalse(StatusApplicationContext::isActive());
    }

    public function testForScopeReturnsTheSameInstancePerScope(): void
    {
        self::assertSame(StatusApplicationContext::forScope('shop_a'), StatusApplicationContext::forScope('shop_a'));
        self::assertNotSame(StatusApplicationContext::forScope('shop_a'), StatusApplicationContext::forScope('shop_b'));
    }

    public function testResetDropsOnlyTheNamedScope(): void
    {
        $shopA = StatusApplicationContext::forScope('shop_a');
        $shopB = StatusApplicationContext::forScope('shop_b');

        StatusApplicationContext::reset('shop_a');

        self::assertNotSame($shopA, StatusApplicationContext::forScope('shop_a'));
        self::assertSame($shopB, StatusApplicationContext::forScope('shop_b'));
    }

    /**
     * The deprecated static entry point still drives the default scope, so existing single-shop integrations keep
     * working unchanged.
     */
    public function testTheDeprecatedStaticRunDrivesTheDefaultScope(): void
    {
        $observed = StatusApplicationContext::run(static fn (): bool => StatusApplicationContext::forScope()->isActiveInScope());

        self::assertTrue($observed);
        self::assertFalse(StatusApplicationContext::isActive());
    }
}
