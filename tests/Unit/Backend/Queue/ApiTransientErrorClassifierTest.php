<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Queue;

use Comfino\Backend\Queue\ApiTransientErrorClassifier;
use Comfino\Backend\Queue\QueueErrorDisposition;
use Psr\Http\Client\ClientExceptionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiTransientErrorClassifierTest extends TestCase
{
    private ApiTransientErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new ApiTransientErrorClassifier();
    }

    /**
     * @dataProvider statusCodeProvider
     */
    public function testClassifiesHttpStatusCodes(
        string $operationType,
        int $statusCode,
        QueueErrorDisposition $expected
    ): void {
        self::assertSame(
            $expected,
            $this->classifier->classify($operationType, new HttpErrorStub($statusCode))
        );
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: QueueErrorDisposition}>
     */
    public static function statusCodeProvider(): array
    {
        return [
            '500 → retry' => ['cancel_order', 500, QueueErrorDisposition::Retry],
            '503 → retry' => ['cancel_order', 503, QueueErrorDisposition::Retry],
            '504 → retry' => ['cancel_order', 504, QueueErrorDisposition::Retry],
            '429 → retry' => ['cancel_order', 429, QueueErrorDisposition::Retry],
            'cancel 404 → treat as success' => ['cancel_order', 404, QueueErrorDisposition::TreatAsSuccess],
            'cancel 409 → treat as success' => ['cancel_order', 409, QueueErrorDisposition::TreatAsSuccess],
            'other op 404 → drop' => ['refund_order', 404, QueueErrorDisposition::DropPermanent],
            '400 → drop' => ['cancel_order', 400, QueueErrorDisposition::DropPermanent],
            '401 → drop' => ['cancel_order', 401, QueueErrorDisposition::DropPermanent],
            '403 → drop' => ['cancel_order', 403, QueueErrorDisposition::DropPermanent],
            '405 → drop' => ['cancel_order', 405, QueueErrorDisposition::DropPermanent],
        ];
    }

    public function testNetworkTimeoutIsRetryable(): void
    {
        $curlTimeout = new class ('timed out', 28) extends RuntimeException implements ClientExceptionInterface {
        };

        self::assertSame(
            QueueErrorDisposition::Retry,
            $this->classifier->classify('cancel_order', $curlTimeout)
        );
    }

    public function testUnknownErrorIsRetriedConservatively(): void
    {
        self::assertSame(
            QueueErrorDisposition::Retry,
            $this->classifier->classify('cancel_order', new RuntimeException('unexpected'))
        );
    }

    public function testAbsorbNotFoundOperationsAreConfigurable(): void
    {
        $classifier = new ApiTransientErrorClassifier(null, ['refund_order']);

        self::assertSame(
            QueueErrorDisposition::TreatAsSuccess,
            $classifier->classify('refund_order', new HttpErrorStub(404))
        );
        self::assertSame(
            QueueErrorDisposition::DropPermanent,
            $classifier->classify('cancel_order', new HttpErrorStub(404))
        );
    }
}
