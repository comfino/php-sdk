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

use Comfino\Backend\Queue\ApiTransientErrorClassifier;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\RetryableOperationHandlerInterface;
use Comfino\Backend\Queue\JitterInterface;
use Comfino\Backend\Queue\SubmitResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OutboundRequestQueueTest extends TestCase
{
    private const OP = 'cancel_order';

    private InMemoryRetryQueueStorage $storage;
    private RecordingDeadLetterReporter $deadLetters;
    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new InMemoryRetryQueueStorage();
        $this->deadLetters = new RecordingDeadLetterReporter();
        $this->clock = new FixedClock();
    }

    /**
     * @param array<string, RetryableOperationHandlerInterface> $handlers
     * @param JitterInterface|null $jitter Defaults to no jitter, so a requeued request is due again immediately and a
     *                                     test can drive several drains without moving the clock. Pass a real value to
     *                                     assert the per-item backoff itself.
     */
    private function makeQueue(
        InMemoryRetryQueueStorage $storage,
        array $handlers,
        int $maxAttempts = 10,
        ?JitterInterface $jitter = null,
        int $maxConsecutiveTenantFailures = 1
    ): OutboundRequestQueue {
        $queue = new OutboundRequestQueue(
            $storage,
            new ApiTransientErrorClassifier(),
            $this->clock,
            null,
            $this->deadLetters,
            $maxAttempts,
            $jitter ?? new FixedJitter(0),
            maxConsecutiveTenantFailures: $maxConsecutiveTenantFailures
        );

        foreach ($handlers as $type => $handler) {
            $queue->registerHandler($type, $handler);
        }

        return $queue;
    }

    // --- submit() ---

    public function testSubmitDeliversImmediatelyOnSuccess(): void
    {
        $handler = new ScriptedHandler([null]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $result = $queue->submit(self::OP, ['orderId' => '100']);

        self::assertSame(SubmitResult::SentImmediately, $result);
        self::assertSame(0, $this->storage->count());
        self::assertSame([['orderId' => '100']], $handler->calls);
    }

    public function testSubmitQueuesOnTransientFailure(): void
    {
        $handler = ScriptedHandler::alwaysThrows(new HttpErrorStub(503));
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $result = $queue->submit(self::OP, ['orderId' => '100']);

        self::assertSame(SubmitResult::Queued, $result);
        self::assertSame(1, $this->storage->count());

        $queued = $this->storage->peekBatch(1)[0];
        self::assertSame(1, $queued->attempts);
        self::assertStringContainsString('HttpErrorStub', (string) $queued->lastError);
    }

    public function testSubmitDropsOnPermanentFailureAndReports(): void
    {
        $handler = ScriptedHandler::alwaysThrows(new HttpErrorStub(400));
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $result = $queue->submit(self::OP, ['orderId' => '100']);

        self::assertSame(SubmitResult::DroppedPermanent, $result);
        self::assertSame(0, $this->storage->count());
        self::assertSame(1, $this->deadLetters->count());
    }

    public function testSubmitTreatsAlreadyCancelledAsSuccess(): void
    {
        $handler = ScriptedHandler::alwaysThrows(new HttpErrorStub(404));
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $result = $queue->submit(self::OP, ['orderId' => '100']);

        self::assertSame(SubmitResult::SentImmediately, $result);
        self::assertSame(0, $this->storage->count());
        self::assertSame(0, $this->deadLetters->count());
    }

    public function testSubmitWithUnregisteredOperationThrows(): void
    {
        $queue = $this->makeQueue($this->storage, []);

        $this->expectException(InvalidArgumentException::class);

        $queue->submit('unknown_op', []);
    }

    // --- enqueue() ---

    public function testEnqueueDoesNotCallHandler(): void
    {
        $handler = new ScriptedHandler([null]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $queue->enqueue(self::OP, ['orderId' => '100']);

        self::assertSame(1, $this->storage->count());
        self::assertSame(0, $handler->callCount());
    }

    public function testEnqueueDedupsIdenticalPendingRequests(): void
    {
        $queue = $this->makeQueue($this->storage, [self::OP => new ScriptedHandler()]);

        $queue->enqueue(self::OP, ['orderId' => '100']);
        $queue->enqueue(self::OP, ['orderId' => '100']);
        $queue->enqueue(self::OP, ['orderId' => '200']);

        self::assertSame(2, $this->storage->count());
    }

    // --- process() ---

    public function testProcessDeliversInFifoOrder(): void
    {
        $handler = new ScriptedHandler([null, null, null]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $queue->enqueue(self::OP, ['orderId' => '1']);
        $queue->enqueue(self::OP, ['orderId' => '2']);
        $queue->enqueue(self::OP, ['orderId' => '3']);

        $result = $queue->process(10);

        self::assertSame(3, $result->processed);
        self::assertSame(0, $result->remaining);
        self::assertSame([['orderId' => '1'], ['orderId' => '2'], ['orderId' => '3']], $handler->calls);
    }

    public function testProcessStopsAtFirstTransientFailurePreservingOrder(): void
    {
        // First item fails transiently; remaining items must NOT be attempted this run.
        $handler = new ScriptedHandler([new HttpErrorStub(503)]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $queue->enqueue(self::OP, ['orderId' => '1']);
        $queue->enqueue(self::OP, ['orderId' => '2']);

        $result = $queue->process(10);

        self::assertTrue($result->stoppedOnTransientFailure);
        self::assertSame(0, $result->processed);
        self::assertSame(1, $result->requeued);
        self::assertSame(2, $result->remaining);
        self::assertSame(1, $handler->callCount()); // Only the head was attempted.

        // The failed head retains its place and now has one recorded attempt.
        $head = $this->storage->peekBatch(1)[0];
        self::assertSame(['orderId' => '1'], $head->payload);
        self::assertSame(1, $head->attempts);
    }

    public function testProcessDropsPermanentFailureAndContinues(): void
    {
        $handler = new ScriptedHandler([new HttpErrorStub(400), null]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $queue->enqueue(self::OP, ['orderId' => '1']);
        $queue->enqueue(self::OP, ['orderId' => '2']);

        $result = $queue->process(10);

        self::assertSame(1, $result->processed); // Order 2 delivered.
        self::assertSame(1, $result->deadLettered); // Order 1 dropped.
        self::assertSame(0, $result->remaining);
        self::assertSame(1, $this->deadLetters->count());
    }

    public function testProcessDeadLettersWhenMaxAttemptsExceeded(): void
    {
        $handler = ScriptedHandler::alwaysThrows(new HttpErrorStub(503));
        $queue = $this->makeQueue($this->storage, [self::OP => $handler], maxAttempts: 1);

        $queue->enqueue(self::OP, ['orderId' => '1']);

        $result = $queue->process(10);

        self::assertSame(1, $result->deadLettered);
        self::assertSame(0, $result->remaining);
        self::assertSame(1, $this->deadLetters->count());
    }

    public function testProcessEventuallyDeadLettersAcrossRuns(): void
    {
        $handler = ScriptedHandler::alwaysThrows(new HttpErrorStub(503));
        $queue = $this->makeQueue($this->storage, [self::OP => $handler], maxAttempts: 3);

        $queue->enqueue(self::OP, ['orderId' => '1']);

        $queue->process(10); // attempt 1 → requeued
        self::assertSame(1, $this->storage->count());

        $queue->process(10); // attempt 2 → requeued
        self::assertSame(1, $this->storage->count());

        $result = $queue->process(10); // attempt 3 → dead-letter
        self::assertSame(1, $result->deadLettered);
        self::assertSame(0, $this->storage->count());
        self::assertSame(1, $this->deadLetters->count());
        self::assertSame(3, $this->deadLetters->reports[0]['request']->attempts);
    }

    public function testProcessTreatsAlreadyCancelledAsDelivered(): void
    {
        $handler = ScriptedHandler::alwaysThrows(new HttpErrorStub(409));
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $queue->enqueue(self::OP, ['orderId' => '1']);

        $result = $queue->process(10);

        self::assertSame(1, $result->processed);
        self::assertSame(0, $result->remaining);
        self::assertSame(0, $this->deadLetters->count());
    }

    public function testProcessDropsRequestWithNoRegisteredHandler(): void
    {
        $this->storage->seed(QueuedRequest::create('orphan_op', ['x' => '1'], $this->clock->now()));
        $queue = $this->makeQueue($this->storage, [self::OP => new ScriptedHandler()]);

        $result = $queue->process(10);

        self::assertSame(1, $result->deadLettered);
        self::assertSame(0, $result->remaining);
    }

    public function testProcessEmptyQueueIsNoOp(): void
    {
        $queue = $this->makeQueue($this->storage, [self::OP => new ScriptedHandler()]);

        $result = $queue->process(10);

        self::assertSame(0, $result->processed);
        self::assertSame(0, $result->remaining);
        self::assertFalse($result->stoppedOnTransientFailure);
    }

    // --- tenant identity handed to the handler ---

    public function testProcessPassesTheRequestsTenantKeyToATenantAwareHandler(): void
    {
        /* The defect this covers: the drain runs under the platform's cron scope, so a handler resolving credentials
           from the ambient scope signs every merchant's request with the default merchant's key. */
        $handler = new TenantAwareScriptedHandler([null, null]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $queue->enqueue(self::OP, ['orderId' => '1'], 'tenant-a');
        $queue->enqueue(self::OP, ['orderId' => '2'], 'tenant-b');

        $result = $queue->process(10);

        self::assertSame(2, $result->processed);
        self::assertSame(['tenant-a', 'tenant-b'], $handler->tenantKeys());
        self::assertSame(0, $handler->tenantBlindCalls);
    }

    public function testProcessPassesNullTenantKeyForRequestsCarryingNoTenantIdentity(): void
    {
        // Rows enqueued before the tenant key existed must keep the ambient behavior rather than fail to deliver.
        $handler = new TenantAwareScriptedHandler([null]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $queue->enqueue(self::OP, ['orderId' => '1']);

        $queue->process(10);

        self::assertSame([null], $handler->tenantKeys());
    }

    public function testSubmitPassesTheTenantKeyToATenantAwareHandler(): void
    {
        $handler = new TenantAwareScriptedHandler([null]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $result = $queue->submit(self::OP, ['orderId' => '1'], 'tenant-a');

        self::assertSame(SubmitResult::SentImmediately, $result);
        self::assertSame(['tenant-a'], $handler->tenantKeys());
        self::assertSame(0, $handler->tenantBlindCalls);
    }

    public function testRequeuedRequestKeepsItsTenantKeyOnTheNextDrain(): void
    {
        $handler = new TenantAwareScriptedHandler([new HttpErrorStub(503), null]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $queue->enqueue(self::OP, ['orderId' => '1'], 'tenant-a');

        $queue->process(10);
        $queue->process(10);

        self::assertSame(['tenant-a', 'tenant-a'], $handler->tenantKeys());
    }

    public function testTenantBlindHandlersAreStillCalledThroughExecute(): void
    {
        // A single-tenant integration's handler must be unaffected by the tenant key travelling on the request.
        $handler = new ScriptedHandler([null]);
        $queue = $this->makeQueue($this->storage, [self::OP => $handler]);

        $queue->enqueue(self::OP, ['orderId' => '1'], 'tenant-a');

        $result = $queue->process(10);

        self::assertSame(1, $result->processed);
        self::assertSame([['orderId' => '1']], $handler->calls);
    }

    public function testZeroMaxAttemptsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OutboundRequestQueue(
            $this->storage,
            new ApiTransientErrorClassifier(),
            $this->clock,
            null,
            null,
            0,
        );
    }
}
