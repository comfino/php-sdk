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

final class AbstractStatusAdapterTest extends TestCase
{
    /**
     * Builds a concrete adapter that records every applyStatus() call for later assertions.
     *
     * @param string[] $ignoredStatuses
     * @param string[] $forbiddenStatuses
     * @param array<string, string> $statusMap
     */
    private function makeAdapter(array $ignoredStatuses, array $forbiddenStatuses, array $statusMap, string $scope = ''): RecordingStatusAdapter
    {
        return new RecordingStatusAdapter($ignoredStatuses, $forbiddenStatuses, $statusMap, $scope);
    }

    protected function tearDown(): void
    {
        StatusApplicationContext::reset();

        parent::tearDown();
    }

    /**
     * The adapter enters *its own* tenant's context, so applying a status for one merchant cannot suppress an
     * unrelated, legitimate event for another merchant being served by the same process.
     */
    public function testApplyStatusEntersTheAdaptersOwnScopeOnly(): void
    {
        $adapter = $this->makeAdapter([], [], ['ACCEPTED' => 'processing'], 'shop_a');

        $adapter->setStatus('100', 'ACCEPTED');

        $this->assertTrue($adapter->applied[0]['scopeActive']);
        $this->assertFalse(StatusApplicationContext::isActive('shop_b'));
        $this->assertFalse(StatusApplicationContext::isActive('shop_a'));
    }

    public function testTheAdapterExposesItsScope(): void
    {
        $this->assertSame('shop_a', $this->makeAdapter([], [], [], 'shop_a')->getScope());
    }

    public function testIgnoredStatusSkipsApplyStatus(): void
    {
        $adapter = $this->makeAdapter(['WAITING_FOR_PAYMENT'], [], ['WAITING_FOR_PAYMENT' => 'pending']);

        $adapter->setStatus('100', 'WAITING_FOR_PAYMENT');

        $this->assertSame([], $adapter->applied);
    }

    public function testForbiddenStatusSkipsApplyStatus(): void
    {
        $adapter = $this->makeAdapter([], ['RESIGN'], ['RESIGN' => 'canceled']);

        $adapter->setStatus('100', 'RESIGN');

        $this->assertSame([], $adapter->applied);
    }

    public function testUnmappedStatusSkipsApplyStatus(): void
    {
        $adapter = $this->makeAdapter([], [], ['ACCEPTED' => 'processing']);

        $adapter->setStatus('100', 'SOME_UNKNOWN_STATUS');

        $this->assertSame([], $adapter->applied);
    }

    public function testMappedStatusCallsApplyStatusWithPlatformCode(): void
    {
        $adapter = $this->makeAdapter([], [], ['ACCEPTED' => 'processing']);

        $adapter->setStatus('100', 'ACCEPTED');

        $this->assertCount(1, $adapter->applied);
        $this->assertSame('100', $adapter->applied[0]['orderId']);
        $this->assertSame('processing', $adapter->applied[0]['platformStatusCode']);
        $this->assertSame('ACCEPTED', $adapter->applied[0]['comfinoStatus']);
    }

    public function testStatusIsNormalizedToUppercaseBeforeRouting(): void
    {
        $adapter = $this->makeAdapter([], [], ['ACCEPTED' => 'processing']);

        $adapter->setStatus('100', 'accepted');

        $this->assertCount(1, $adapter->applied);
        $this->assertSame('processing', $adapter->applied[0]['platformStatusCode']);
        $this->assertSame('ACCEPTED', $adapter->applied[0]['comfinoStatus']);
    }

    public function testLowercaseIgnoredStatusIsStillSkipped(): void
    {
        $adapter = $this->makeAdapter(['WAITING_FOR_PAYMENT'], [], ['WAITING_FOR_PAYMENT' => 'pending']);

        $adapter->setStatus('100', 'waiting_for_payment');

        $this->assertSame([], $adapter->applied);
    }

    public function testApplyStatusRunsInsideStatusApplicationContext(): void
    {
        $adapter = $this->makeAdapter([], [], ['ACCEPTED' => 'processing']);

        $this->assertFalse(StatusApplicationContext::isActive());

        $adapter->setStatus('100', 'ACCEPTED');

        $this->assertTrue($adapter->applied[0]['contextActive']);
        $this->assertFalse(StatusApplicationContext::isActive());
    }
}
