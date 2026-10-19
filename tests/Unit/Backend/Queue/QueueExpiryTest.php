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
use Comfino\Backend\Queue\OutboundRequestQueueProcessor;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\RequestExpired;
use Comfino\Backend\Queue\RetryableOperationHandlerInterface;
use Comfino\Backend\Queue\RetryQueueStorageInterface;
use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Backend\Cache\ScopedCache;
use PHPUnit\Framework\TestCase;

final class QueueExpiryTest extends TestCase
{
    private FixedClock $clock;
    private RecordingDeadLetterReporter $deadLetters;
    /** @var array<int, array<string, scalar>> */
    private array $delivered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock(1_000_000);
        $this->deadLetters = new RecordingDeadLetterReporter();
    }

    public function testExpiredRequestIsDeadLetteredAndReportedInsteadOfDelivered(): void
    {
        $storage = new PurgeableInMemoryRetryQueueStorage();
        $storage->enqueue(QueuedRequest::create('op', ['n' => 'old'], 1_000_000 - 7200));
        $storage->enqueue(QueuedRequest::create('op', ['n' => 'fresh'], 1_000_000 - 60));

        $result = $this->queue($storage)->process(10, null, 3600);

        $this->assertSame([['n' => 'fresh']], $this->delivered);
        $this->assertSame(1, $result->deadLettered);
        $this->assertSame(1, $this->deadLetters->count());
        $this->assertInstanceOf(RequestExpired::class, $this->deadLetters->reports[0]['error']);
        $this->assertSame(0, $storage->count());
    }

    public function testExpiredRowsTheDrainDidNotReachArePurged(): void
    {
        $storage = new PurgeableInMemoryRetryQueueStorage();

        for ($i = 0; $i < 5; $i++) {
            $storage->enqueue(QueuedRequest::create('op', ['n' => $i], 1_000_000 - 7200));
        }

        // A batch of 2 reaches two rows; the other three are deleted by the purge.
        $result = $this->queue($storage)->process(2, null, 3600);

        $this->assertSame([], $this->delivered);
        $this->assertSame(5, $result->deadLettered);
        $this->assertSame(0, $storage->count());
    }

    public function testNonPurgeableStorageStillExpiresWhatTheDrainReaches(): void
    {
        $inner = new InMemoryRetryQueueStorage();

        for ($i = 0; $i < 3; $i++) {
            $inner->enqueue(QueuedRequest::create('op', ['n' => $i], 1_000_000 - 7200));
        }

        $result = $this->queue($inner)->process(2, null, 3600);

        $this->assertSame(2, $result->deadLettered);
        $this->assertSame(1, $inner->count());
    }

    public function testWithoutMaxAgeOldRequestsAreDelivered(): void
    {
        $storage = new PurgeableInMemoryRetryQueueStorage();
        $storage->enqueue(QueuedRequest::create('op', ['n' => 'old'], 1));

        $this->queue($storage)->process(10);

        $this->assertSame([['n' => 'old']], $this->delivered);
    }

    public function testProcessorPassesItsMaxAgeToTheQueue(): void
    {
        $storage = new PurgeableInMemoryRetryQueueStorage();
        $storage->enqueue(QueuedRequest::create('op', ['n' => 'old'], 1_000_000 - 7200));

        $processor = new OutboundRequestQueueProcessor(
            $this->queue($storage),
            $this->clock,
            new FixedJitter(0),
            cache: new ScopedCache(new ArrayCachePool()),
            maxAgeSeconds: 3600
        );

        $this->assertSame(1, $processor->process()->deadLettered);
        $this->assertSame([], $this->delivered);
    }

    private function queue(RetryQueueStorageInterface $storage): OutboundRequestQueue
    {
        $queue = new OutboundRequestQueue(
            $storage,
            new ApiTransientErrorClassifier(),
            $this->clock,
            null,
            $this->deadLetters,
            10,
            new FixedJitter(0)
        );
        $queue->registerHandler('op', new class (function (array $payload): void {
            $this->delivered[] = $payload;
        }) implements RetryableOperationHandlerInterface {
            public function __construct(private readonly \Closure $deliver)
            {
            }

            public function execute(array $payload): void
            {
                ($this->deliver)($payload);
            }
        });

        return $queue;
    }
}
