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
use Comfino\Backend\Queue\JitterInterface;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\TenantPauseReporterInterface;
use PHPUnit\Framework\TestCase;

/**
 * The head-of-line blocking the queue used to have, and the three changes that removed it.
 */
final class OutboundRequestQueueFairnessTest extends TestCase
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
     * The defect in one test: one merchant whose credentials are refused used to stall every other merchant's queue
     * behind it, indefinitely, because the stalled item stayed at the front. Now its partition is held and everyone
     * else drains.
     */
    public function testABrokenTenantDoesNotStallTheOthers(): void
    {
        $handler = new PerTenantHandler(['shop_broken' => new HttpErrorStub(401)]);
        $pauses = new RecordingPauseReporter();
        $queue = $this->makeQueue($handler, pauseReporter: $pauses);

        // The broken tenant enqueues first, so under the old FIFO drain it sat at the head of the queue.
        $queue->enqueue(self::OP, ['orderId' => '1', 'tenant' => 'shop_broken'], 'shop_broken');
        $queue->enqueue(self::OP, ['orderId' => '2', 'tenant' => 'shop_broken'], 'shop_broken');
        $queue->enqueue(self::OP, ['orderId' => '1', 'tenant' => 'shop_a'], 'shop_a');
        $queue->enqueue(self::OP, ['orderId' => '1', 'tenant' => 'shop_b'], 'shop_b');

        $result = $queue->process(10);

        self::assertSame(2, $result->processed, 'The healthy tenants must both drain.');
        self::assertSame(['shop_broken'], $result->pausedTenants);
        self::assertFalse(
            $result->stoppedOnTransientFailure,
            'One misconfigured tenant is not evidence that the API is down, so the global cooldown must not engage.'
        );
        self::assertSame([['orderId' => '1', 'tenant' => 'shop_a'], ['orderId' => '1', 'tenant' => 'shop_b']], $handler->delivered);

        // Both of the broken tenant's requests survive - a 401 is a configuration event, not a reason to discard.
        self::assertSame(2, $this->storage->count('shop_broken'));
        self::assertSame(0, $this->deadLetters->count());
    }

    /**
     * A refused credential must raise an alert. Silence is what let a merchant's cancellations disappear for a week.
     */
    public function testAPausedTenantIsReported(): void
    {
        $pauses = new RecordingPauseReporter();
        $queue = $this->makeQueue(new PerTenantHandler(['shop_broken' => new HttpErrorStub(403)]), pauseReporter: $pauses);

        $queue->enqueue(self::OP, ['orderId' => '1', 'tenant' => 'shop_broken'], 'shop_broken');
        $queue->process(10);

        self::assertCount(1, $pauses->reports);
        self::assertSame('shop_broken', $pauses->reports[0]['tenantKey']);
        self::assertSame('credentials_rejected', $pauses->reports[0]['reason']);
    }

    /**
     * A pause must not burn the attempt budget: dead-lettering a merchant's traffic because an operator rotated a key
     * on one side only is the outcome PauseTenant exists to prevent.
     */
    public function testAPausedRequestKeepsItsAttemptBudgetAndIsDeferred(): void
    {
        $queue = $this->makeQueue(new PerTenantHandler(['shop_broken' => new HttpErrorStub(401)]), maxAttempts: 1, tenantPauseSeconds: 900);

        $queue->enqueue(self::OP, ['orderId' => '1', 'tenant' => 'shop_broken'], 'shop_broken');
        $queue->process(10);

        $pending = $this->storage->peekBatch(10, 'shop_broken');

        self::assertCount(1, $pending);
        self::assertSame(0, $pending[0]->attempts, 'A refused key is not the request failing on its own merits.');
        self::assertSame($this->clock->now() + 900, $pending[0]->availableAt);
    }

    /**
     * Round-robin: one busy tenant must not consume the whole batch.
     */
    public function testABatchIsSharedOutBetweenTenants(): void
    {
        $handler = new PerTenantHandler();
        $queue = $this->makeQueue($handler);

        for ($i = 1; $i <= 5; $i++) {
            $queue->enqueue(self::OP, ['orderId' => (string) $i, 'tenant' => 'shop_busy'], 'shop_busy');
        }

        $queue->enqueue(self::OP, ['orderId' => '1', 'tenant' => 'shop_quiet'], 'shop_quiet');

        $result = $queue->process(4);

        self::assertSame(4, $result->processed);
        self::assertContains(
            ['orderId' => '1', 'tenant' => 'shop_quiet'],
            $handler->delivered,
            'The quiet tenant enqueued last but must not wait behind the busy one.'
        );
    }

    /**
     * Per-item backoff: a requeued request used to be retried on the very next drain, with no delay at all. Now it is
     * scheduled forward, so the next run skips it instead of spending a batch slot on it.
     */
    public function testARequeuedRequestIsNotDueOnTheNextDrain(): void
    {
        $queue = $this->makeQueue(new PerTenantHandler(['shop_a' => new HttpErrorStub(503)]), maxAttempts: 5, jitter: new FixedJitter(60));

        $queue->enqueue(self::OP, ['orderId' => '1', 'tenant' => 'shop_a'], 'shop_a');

        $first = $queue->process(10);

        self::assertSame(1, $first->requeued);

        $pending = $this->storage->peekBatch(10, 'shop_a');

        self::assertSame($this->clock->now() + 60, $pending[0]->availableAt);

        $second = $queue->process(10);

        self::assertSame(0, $second->requeued, 'The item is not due, so the drain must not attempt it again.');
        self::assertSame(0, $second->processed);

        $this->clock->advance(60);

        self::assertSame(1, $queue->process(10)->requeued, 'Once due, it is attempted again.');
    }

    /**
     * With a single tenant and the default threshold of one failure, the drain behaves exactly as it did before the
     * fairness work: it stops, and the processor's cooldown gate engages.
     */
    public function testASingleTenantQueueStillStopsOnTheFirstTransientFailure(): void
    {
        $handler = new PerTenantHandler(['' => new HttpErrorStub(503)]);
        $queue = $this->makeQueue($handler, maxAttempts: 5);

        $queue->enqueue(self::OP, ['orderId' => '1']);
        $queue->enqueue(self::OP, ['orderId' => '2']);

        $result = $queue->process(10);

        self::assertSame(1, $result->requeued);
        self::assertSame(0, $result->processed);
        self::assertTrue($result->stoppedOnTransientFailure);
        self::assertSame([null], $result->pausedTenants);
        self::assertCount(1, $handler->attempted, 'The second request must not be attempted after the first failed.');
    }

    /**
     * Raising the threshold lets a tenant absorb a flaky request before its partition is held.
     */
    public function testTheFailureThresholdIsConfigurable(): void
    {
        $handler = new PerTenantHandler(['shop_a' => new HttpErrorStub(503)]);
        $queue = $this->makeQueue($handler, maxAttempts: 5, maxConsecutiveTenantFailures: 3);

        for ($i = 1; $i <= 4; $i++) {
            $queue->enqueue(self::OP, ['orderId' => (string) $i, 'tenant' => 'shop_a'], 'shop_a');
        }

        $result = $queue->process(10);

        self::assertSame(3, $result->requeued);
        self::assertCount(3, $handler->attempted);
        self::assertSame(['shop_a'], $result->pausedTenants);
    }

    /**
     * The streak has to be consecutive to mean anything: a tenant whose requests mostly succeed is not a tenant to
     * pause, however many isolated failures it accumulates over a long drain.
     */
    public function testASuccessClearsTheFailureStreak(): void
    {
        $handler = new PerTenantHandler();
        $handler->failFor('1', new HttpErrorStub(503));
        $handler->failFor('3', new HttpErrorStub(503));

        $queue = $this->makeQueue($handler, maxAttempts: 5, maxConsecutiveTenantFailures: 2);

        foreach (['1', '2', '3', '4'] as $orderId) {
            $queue->enqueue(self::OP, ['orderId' => $orderId, 'tenant' => 'shop_a'], 'shop_a');
        }

        $result = $queue->process(10);

        self::assertSame([], $result->pausedTenants);
        self::assertSame(2, $result->processed);
        self::assertSame(2, $result->requeued);
    }

    /**
     * Order numbers are only unique within a shop: two merchants cancelling their own order "1042" are two distinct
     * operations, and the old tenant-blind dedup key collapsed them into one, losing a cancellation.
     */
    public function testTwoTenantsCancellingTheSameOrderNumberAreNotDeduplicated(): void
    {
        $queue = $this->makeQueue(new PerTenantHandler());

        $queue->enqueue(self::OP, ['orderId' => '1042'], 'shop_a');
        $queue->enqueue(self::OP, ['orderId' => '1042'], 'shop_b');

        self::assertSame(2, $this->storage->count());
        self::assertSame(1, $this->storage->count('shop_a'));
        self::assertSame(1, $this->storage->count('shop_b'));
    }

    public function testTheSameTenantEnqueuingTheSameOperationTwiceIsStillDeduplicated(): void
    {
        $queue = $this->makeQueue(new PerTenantHandler());

        $queue->enqueue(self::OP, ['orderId' => '1042'], 'shop_a');
        $queue->enqueue(self::OP, ['orderId' => '1042'], 'shop_a');

        self::assertSame(1, $this->storage->count('shop_a'));
    }

    /**
     * An operator who has just fixed one merchant's key wants to retry that merchant alone, without touching anyone
     * else's queue.
     */
    public function testASingleTenantsPartitionCanBeDrainedOnItsOwn(): void
    {
        $handler = new PerTenantHandler();
        $queue = $this->makeQueue($handler);

        $queue->enqueue(self::OP, ['orderId' => '1', 'tenant' => 'shop_a'], 'shop_a');
        $queue->enqueue(self::OP, ['orderId' => '1', 'tenant' => 'shop_b'], 'shop_b');

        $result = $queue->process(10, 'shop_a');

        self::assertSame(1, $result->processed);
        self::assertSame([['orderId' => '1', 'tenant' => 'shop_a']], $handler->delivered);
        self::assertSame(1, $this->storage->count('shop_b'));
    }

    /**
     * A compliant storage adapter filters a not-yet-due request out, so the queue never sees it and no batch slot is
     * spent.
     */
    public function testACompliantStorageHidesARequestThatIsNotDue(): void
    {
        $handler = new PerTenantHandler();
        $queue = $this->makeQueue($handler);

        $this->storage->seed($this->deferredRequest());

        $result = $queue->process(10, 'shop_a');

        self::assertSame(0, $result->notDue, 'Storage filtered it, so the queue had nothing to skip.');
        self::assertSame(0, $result->processed);
        self::assertSame([], $handler->attempted);
    }

    /**
     * Honoring the due-at gate is a SHOULD, not a MUST, so an adapter that ignores it must still be correct: the queue
     * re-checks and skips, paying only the wasted slot the gate exists to save.
     */
    public function testTheQueueSkipsANotDueRequestAGateIgnoringStorageHandsIt(): void
    {
        $storage = new GateIgnoringRetryQueueStorage();
        $storage->seed($this->deferredRequest());

        $handler = new PerTenantHandler();

        $queue = new OutboundRequestQueue(
            $storage,
            new ApiTransientErrorClassifier(),
            $this->clock,
            null,
            $this->deadLetters,
            10,
            new FixedJitter(0)
        );
        $queue->registerHandler(self::OP, $handler);

        $result = $queue->process(10, 'shop_a');

        self::assertSame(1, $result->notDue);
        self::assertSame(0, $result->processed);
        self::assertSame([], $handler->attempted);
    }

    /**
     * A request already failed once and deferred five minutes into the future.
     */
    private function deferredRequest(): QueuedRequest
    {
        return new QueuedRequest(
            null,
            self::OP,
            ['orderId' => '1', 'tenant' => 'shop_a'],
            1,
            $this->clock->now(),
            'previous failure',
            'shop_a',
            $this->clock->now() + 300
        );
    }

    private function makeQueue(
        PerTenantHandler $handler,
        int $maxAttempts = 10,
        ?JitterInterface $jitter = null,
        int $maxConsecutiveTenantFailures = 1,
        ?TenantPauseReporterInterface $pauseReporter = null,
        int $tenantPauseSeconds = 900
    ): OutboundRequestQueue {
        $queue = new OutboundRequestQueue(
            $this->storage,
            new ApiTransientErrorClassifier(),
            $this->clock,
            null,
            $this->deadLetters,
            $maxAttempts,
            $jitter ?? new FixedJitter(0),
            60,
            3600,
            $maxConsecutiveTenantFailures,
            $pauseReporter,
            $tenantPauseSeconds
        );

        $queue->registerHandler(self::OP, $handler);

        return $queue;
    }
}
