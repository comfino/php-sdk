<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Queue;

use Comfino\Api\Exception\Conflict;
use Comfino\Api\Exception\NotFound;
use Comfino\Backend\Queue\ApiTransientErrorClassifier;
use Comfino\Backend\Queue\OutboundRequestQueue;
use Comfino\Backend\Queue\QueueErrorDisposition;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueParityTest extends TestCase
{
    public function testLastErrorIsRedactedAndCapped(): void
    {
        $storage = new InMemoryRetryQueueStorage();
        $queue = new OutboundRequestQueue(
            $storage,
            new ApiTransientErrorClassifier(),
            new FixedClock(),
            null,
            new RecordingDeadLetterReporter(),
            10,
            new FixedJitter(0)
        );
        $queue->registerHandler('cancel_order', ScriptedHandler::alwaysThrows(
            new HttpErrorStub(503, 'upstream said jan.kowalski@example.com ' . str_repeat('<html>zażółć</html>', 200))
        ));

        $queue->submit('cancel_order', ['orderId' => '100']);

        $lastError = (string) $storage->peekBatch(1)[0]->lastError;

        $this->assertLessThanOrEqual(OutboundRequestQueue::MAX_LAST_ERROR_LENGTH, strlen($lastError));
        $this->assertStringStartsWith(HttpErrorStub::class, $lastError);
        $this->assertStringNotContainsString('jan.kowalski@example.com', $lastError);
        $this->assertMatchesRegularExpression('//u', $lastError);
    }

    public function testADeclaredIdempotentFailureIsTreatedAsSuccessForAnyOperationType(): void
    {
        $classifier = new ApiTransientErrorClassifier();

        $this->assertSame(
            QueueErrorDisposition::TreatAsSuccess,
            $classifier->classify('some_other_operation', (new NotFound('gone', 404))->setErrorDetails(false, true))
        );
        $this->assertSame(
            QueueErrorDisposition::TreatAsSuccess,
            $classifier->classify('some_other_operation', (new Conflict('done', 409))->setErrorDetails(false, true))
        );
    }

    public function testWithoutTheFlagTheOperationListDecides(): void
    {
        $classifier = new ApiTransientErrorClassifier();

        $this->assertSame(QueueErrorDisposition::DropPermanent, $classifier->classify('some_other_operation', new NotFound('gone', 404)));
        $this->assertSame(QueueErrorDisposition::TreatAsSuccess, $classifier->classify('cancel_order', new NotFound('gone', 404)));
        $this->assertSame(QueueErrorDisposition::Retry, $classifier->classify('cancel_order', new RuntimeException('?')));
    }
}
