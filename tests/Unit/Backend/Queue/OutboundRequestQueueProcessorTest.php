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

use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Queue\ApiTransientErrorClassifier;
use Comfino\Backend\Queue\JitterInterface;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\OutboundRequestQueueProcessor;
use PHPUnit\Framework\TestCase;

final class OutboundRequestQueueProcessorTest extends TestCase
{
    private const OP = 'cancel_order';
    private const COOLDOWN = 300;

    private InMemoryRetryQueueStorage $storage;
    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        CacheManager::init(new ArrayCachePool());

        $this->storage = new InMemoryRetryQueueStorage();
        $this->clock = new FixedClock();
    }

    protected function tearDown(): void
    {
        CacheManager::reset();

        parent::tearDown();
    }

    private function makeProcessor(OutboundRequestQueue $queue, ?JitterInterface $jitter = null): OutboundRequestQueueProcessor
    {
        return new OutboundRequestQueueProcessor(
            $queue,
            $this->clock,
            $jitter ?? new FixedJitter(0),
            20,
            self::COOLDOWN
        );
    }

    private function makeQueue(ScriptedHandler $handler): OutboundRequestQueue
    {
        $queue = new OutboundRequestQueue($this->storage, new ApiTransientErrorClassifier(), $this->clock);
        $queue->registerHandler(self::OP, $handler);

        return $queue;
    }

    public function testProcessDelegatesToQueueWhenNotCoolingDown(): void
    {
        $queue = $this->makeQueue(new ScriptedHandler([null, null]));
        $queue->enqueue(self::OP, ['orderId' => '1']);
        $queue->enqueue(self::OP, ['orderId' => '2']);

        $result = $this->makeProcessor($queue)->process();

        self::assertFalse($result->skipped);
        self::assertSame(2, $result->processed);
    }

    public function testCooldownSuppressesSubsequentDrainAfterTransientFailure(): void
    {
        $queue = $this->makeQueue(ScriptedHandler::alwaysThrows(new HttpErrorStub(503)));
        $queue->enqueue(self::OP, ['orderId' => '1']);
        $queue->enqueue(self::OP, ['orderId' => '2']);

        $processor = $this->makeProcessor($queue);
        $first = $processor->process();

        self::assertTrue($first->stoppedOnTransientFailure);
        self::assertFalse($first->skipped);

        // Immediately retrying is suppressed by the cooldown gate.
        $second = $processor->process();
        self::assertTrue($second->skipped);
        self::assertSame(2, $second->remaining);
    }

    public function testDrainResumesAfterCooldownElapses(): void
    {
        $handler = new ScriptedHandler([new HttpErrorStub(503)]); // fail once, then succeed
        $queue = $this->makeQueue($handler);
        $queue->enqueue(self::OP, ['orderId' => '1']);

        $processor = $this->makeProcessor($queue);

        $processor->process(); // transient failure → cooldown begins

        self::assertTrue($processor->process()->skipped);

        $this->clock->advance(self::COOLDOWN + 1);

        $resumed = $processor->process(); // cooldown elapsed → drains successfully

        self::assertFalse($resumed->skipped);
        self::assertSame(1, $resumed->processed);
        self::assertSame(0, $resumed->remaining);
    }

    public function testSuccessfulDrainDoesNotTriggerCooldown(): void
    {
        $queue = $this->makeQueue(new ScriptedHandler([null]));
        $queue->enqueue(self::OP, ['orderId' => '1']);

        $processor = $this->makeProcessor($queue);

        self::assertSame(1, $processor->process()->processed);
        // No transient failure → no cooldown → a second run is allowed (and is a no-op on the empty queue).
        self::assertFalse($processor->process()->skipped);
    }

    public function testJitterExtendsCooldownBeyondConfiguredValue(): void
    {
        $queue = $this->makeQueue(ScriptedHandler::alwaysThrows(new HttpErrorStub(503)));
        $queue->enqueue(self::OP, ['orderId' => '1']);

        // Half of COOLDOWN (300 / 2 = 150) is the maximum jitter beginCooldown() can draw.
        $processor = $this->makeProcessor($queue, new FixedJitter(150));
        $processor->process(); // transient failure → cooldown begins, jittered to COOLDOWN + 150

        // The un-jittered cooldown has elapsed, but the jittered one has not.
        $this->clock->advance(self::COOLDOWN + 1);

        self::assertTrue($processor->process()->skipped);

        // Now the jittered cooldown has elapsed too.
        $this->clock->advance(150);

        self::assertFalse($processor->process()->skipped);
    }

    public function testZeroJitterBehavesLikeUnjitteredCooldown(): void
    {
        $handler = new ScriptedHandler([new HttpErrorStub(503)]); // fail once, then succeed
        $queue = $this->makeQueue($handler);
        $queue->enqueue(self::OP, ['orderId' => '1']);

        $processor = $this->makeProcessor($queue, new FixedJitter(0));
        $processor->process(); // transient failure → cooldown begins, unmodified by zero jitter

        $this->clock->advance(self::COOLDOWN + 1);

        $resumed = $processor->process();

        self::assertFalse($resumed->skipped);
        self::assertSame(1, $resumed->processed);
    }
}
