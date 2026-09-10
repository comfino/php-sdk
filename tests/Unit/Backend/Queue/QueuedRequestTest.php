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

use Comfino\Backend\Queue\QueuedRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueuedRequestTest extends TestCase
{
    public function testCreateStartsWithZeroAttemptsAndNoId(): void
    {
        $request = QueuedRequest::create('cancel_order', ['orderId' => '100'], 1234);

        self::assertNull($request->id);
        self::assertSame('cancel_order', $request->operationType);
        self::assertSame(['orderId' => '100'], $request->payload);
        self::assertSame(0, $request->attempts);
        self::assertSame(1234, $request->createdAt);
        self::assertNull($request->lastError);
    }

    public function testEmptyOperationTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueuedRequest::create('', ['orderId' => '100'], 1234);
    }

    public function testWithAttemptFailureIncrementsAndRecordsError(): void
    {
        $request = QueuedRequest::create('cancel_order', ['orderId' => '100'], 1234)
            ->withAttemptFailure('Timeout: boom');

        self::assertSame(1, $request->attempts);
        self::assertSame('Timeout: boom', $request->lastError);

        $again = $request->withAttemptFailure('Timeout: boom again');

        self::assertSame(2, $again->attempts);
        self::assertSame('Timeout: boom again', $again->lastError);
    }

    public function testWithIdIsImmutable(): void
    {
        $request = QueuedRequest::create('cancel_order', ['orderId' => '100'], 1234);
        $withId = $request->withId(42);

        self::assertNull($request->id);
        self::assertSame(42, $withId->id);
    }

    public function testDedupKeyIsStableForSamePayloadAndDiffersOtherwise(): void
    {
        $a = QueuedRequest::create('cancel_order', ['orderId' => '100'], 1);
        $b = QueuedRequest::create('cancel_order', ['orderId' => '100'], 999);
        $c = QueuedRequest::create('cancel_order', ['orderId' => '101'], 1);
        $d = QueuedRequest::create('refund_order', ['orderId' => '100'], 1);

        self::assertSame($a->dedupKey(), $b->dedupKey());
        self::assertNotSame($a->dedupKey(), $c->dedupKey());
        self::assertNotSame($a->dedupKey(), $d->dedupKey());
    }

    public function testRoundTripsThroughArray(): void
    {
        $request = (QueuedRequest::create('cancel_order', ['orderId' => '100'], 1234))
            ->withId(7)
            ->withAttemptFailure('boom');

        $restored = QueuedRequest::fromArray($request->toArray());

        self::assertEquals($request, $restored);
    }
}
