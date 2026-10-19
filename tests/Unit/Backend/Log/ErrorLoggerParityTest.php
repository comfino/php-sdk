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

namespace Comfino\Tests\Unit\Backend\Log;

use Comfino\Api\ClientInterface;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\NotFound;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Exception\TooManyRequests;
use Comfino\Api\Serializer\Json;
use Comfino\Backend\Factory\OutboundRequestQueueFactory;
use Comfino\Backend\Log\ErrorLogger;
use Comfino\Tests\Unit\Backend\Queue\InMemoryRetryQueueStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use TypeError;

final class ErrorLoggerParityTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir() . '/comfino_parity_' . uniqid('', true) . '.log';

        ErrorLogger::reset();
        putenv('COMFINO_DEV_ENV');
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->logFile) . '/' . pathinfo($this->logFile, PATHINFO_FILENAME) . '*') ?: [] as $file) {
            @unlink($file);
        }

        ErrorLogger::reset();

        parent::tearDown();
    }

    public function testMessageAndTraceAreRedactedInTheLocalLogAndTheQueuedPayload(): void
    {
        $storage = new InMemoryRetryQueueStorage();
        $logger = ErrorLogger::getInstance(
            $this->createMock(ClientInterface::class),
            $this->logFile,
            'shop.test',
            'TestPlatform',
            '/var/www/html/modules/comfino',
            ['php' => '8.2.0'],
            (new OutboundRequestQueueFactory())->create($storage, $this->createMock(ClientInterface::class)),
            new Json()
        );

        $logger->sendError(
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            OperationContext::OrderCreation,
            '400',
            'Rejected jan.kowalski@example.com (+48 600 123 456) from 192.168.1.10, order 100000123',
            null,
            null,
            null,
            "#0 trace for jan.kowalski@example.com\n#1 {main}"
        );

        $log = (string) file_get_contents($this->logFile);
        $queued = (string) json_encode(array_map(static fn ($request) => $request->payload, $storage->peekBatch(10)));

        foreach ([$log, $queued] as $output) {
            $this->assertStringNotContainsString('jan.kowalski@example.com', $output);
            $this->assertStringNotContainsString('600 123 456', $output);
            $this->assertStringNotContainsString('192.168.1.10', $output);
        }

        $this->assertStringContainsString('100000123', $log, 'Order IDs survive the redaction.');
    }

    /**
     * @return array<string, array{\Throwable, ErrorSeverity}>
     */
    public static function severityProvider(): array
    {
        $curlTimeout = new class ('timed out', 28) extends RuntimeException implements ClientExceptionInterface {
        };
        $tlsFailure = new class ('SSL certificate problem', 60) extends RuntimeException implements ClientExceptionInterface {
        };
        $cancelNotFound = (new NotFound('gone', 404))->setErrorDetails(false, true);

        return [
            'connection timeout' => [new ConnectionTimeout(), ErrorSeverity::Warning],
            'curl timeout' => [$curlTimeout, ErrorSeverity::Warning],
            'service unavailable' => [new ServiceUnavailable('down', 503), ErrorSeverity::Warning],
            'html 503' => [(new ServiceUnavailable('down', 503))->setErrorDetails(true, false), ErrorSeverity::Warning],
            'too many requests' => [new TooManyRequests('slow', 429), ErrorSeverity::Warning],
            'cancel not found' => [$cancelNotFound, ErrorSeverity::Warning],
            'not found elsewhere' => [new NotFound('gone', 404), ErrorSeverity::Error],
            'access denied' => [new AccessDenied('no', 403), ErrorSeverity::Error],
            'type error' => [new TypeError('bug'), ErrorSeverity::Error],
            'runtime exception' => [new RuntimeException('?'), ErrorSeverity::Error],
            'tls failure' => [$tlsFailure, ErrorSeverity::Error],
            'response validation' => [new ResponseValidationError('contract'), ErrorSeverity::Error],
        ];
    }

    #[DataProvider('severityProvider')]
    public function testResolveSeverity(\Throwable $error, ErrorSeverity $expected): void
    {
        $this->assertSame($expected, ErrorLogger::resolveSeverity($error));
    }

    public function testResolveSeverityReturnsTheCallersDefaultForUnclassifiedFailures(): void
    {
        $this->assertSame(ErrorSeverity::Critical, ErrorLogger::resolveSeverity(new TypeError('bug'), ErrorSeverity::Critical));
        $this->assertSame(ErrorSeverity::Warning, ErrorLogger::resolveSeverity(new ServiceUnavailable('down', 503), ErrorSeverity::Critical));
    }

    public function testIsReportable(): void
    {
        $this->assertFalse(ErrorLogger::isReportable(new RequestValidationError('bad', 400)));
        $this->assertFalse(ErrorLogger::isReportable(new AuthorizationError('key', 401)));

        $this->assertTrue(ErrorLogger::isReportable(new TooManyRequests('slow', 429)));
        $this->assertTrue(ErrorLogger::isReportable(new ServiceUnavailable('down', 503)));
        $this->assertTrue(ErrorLogger::isReportable(new ResponseValidationError('contract')));
        $this->assertTrue(ErrorLogger::isReportable(new TypeError('bug')));
    }
}
