<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Api;

use Comfino\Api\ClientInterface;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\NotFound;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Backend\Api\ApiErrorProcessor;
use Comfino\Backend\Log\DebugLogger;
use Comfino\Backend\Log\ErrorLogger;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

final class ApiErrorProcessorTest extends TestCase
{
    private ClientInterface&MockObject $apiClient;
    private string $tempLogFile;
    /** @var ShopPluginError[] */
    private array $sentErrors = [];
    private RecordingLogger $debugSink;

    protected function setUp(): void
    {
        parent::setUp();

        ErrorLogger::reset();
        DebugLogger::reset();

        putenv('COMFINO_DEV_ENV');

        $this->tempLogFile = sys_get_temp_dir() . '/comfino_api_error_' . uniqid('', true) . '.log';
        $this->apiClient = $this->createMock(ClientInterface::class);
        $this->apiClient->method('sendLoggedError')->willReturnCallback(
            function (ShopPluginError $error): void {
                $this->sentErrors[] = $error;
            }
        );

        $this->debugSink = new RecordingLogger();

        DebugLogger::init($this->debugSink);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob(sys_get_temp_dir() . '/' . pathinfo($this->tempLogFile, PATHINFO_FILENAME) . '*') ?: [] as $file) {
            unlink($file);
        }

        ErrorLogger::reset();
        DebugLogger::reset();
    }

    public function testConnectionTimeoutLogsTimeoutAndErrorEventsAndReportsExtractedContext(): void
    {
        $exception = new ConnectionTimeout(
            'Connection timed out.',
            28,
            new RuntimeException('cURL error 28', 28),
            2,
            3,
            5,
            'https://api-ecommerce.comfino.pl/v1/orders',
            '{"orderId":"1"}',
            ''
        );

        $details = $this->createProcessor()->process(
            OperationContext::OrderCreation,
            ErrorCategory::ApiError,
            ErrorSeverity::Critical,
            $exception
        );

        self::assertTrue($details->isTimeout);
        self::assertSame(2, $details->connectAttemptIdx);
        self::assertSame(3, $details->connectionTimeout);
        self::assertSame(5, $details->transferTimeout);
        self::assertNull($details->responseBody, 'An empty response body is normalized to null.');

        self::assertSame(['[API_TIMEOUT] order_creation', '[API_ERROR] order_creation'], $this->debugMessages());
        self::assertSame(RuntimeException::class, $this->debugSink->records[0]['context']['exception']);
        self::assertSame(28, $this->debugSink->records[0]['context']['code']);
        self::assertSame(2, $this->debugSink->records[0]['context']['connect_attempt_idx']);
        self::assertSame(ConnectionTimeout::class, $this->debugSink->records[1]['context']['exception']);

        self::assertCount(1, $this->sentErrors);

        $sent = $this->sentErrors[0];

        self::assertSame(ErrorCategory::ApiError, $sent->category);
        self::assertSame(ErrorSeverity::Critical, $sent->severity);
        self::assertSame(OperationContext::OrderCreation, $sent->context);
        self::assertSame('28', $sent->errorCode);
        self::assertSame('https://api-ecommerce.comfino.pl/v1/orders', $sent->apiRequestUrl);
        self::assertSame('{"orderId":"1"}', $sent->apiRequest);
        self::assertNull($sent->apiResponse);
    }

    public function testHttpErrorReportsUrlAndBodies(): void
    {
        $exception = new NotFound('Not found.', 404, null, 'https://api/v1/orders/1', '', '{"error":"x"}');

        $details = $this->createProcessor()->process(
            OperationContext::OrderCancellation,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            $exception
        );

        self::assertSame(404, $details->statusCode);
        self::assertFalse($details->isTimeout);
        self::assertSame(['[API_ERROR] order_cancellation'], $this->debugMessages());
        self::assertCount(1, $this->sentErrors);
        self::assertSame('https://api/v1/orders/1', $this->sentErrors[0]->apiRequestUrl);
        self::assertNull($this->sentErrors[0]->apiRequest);
        self::assertSame('{"error":"x"}', $this->sentErrors[0]->apiResponse);
    }

    public function testNetworkErrorReadsRequestFromPsrException(): void
    {
        $request = new Request('POST', 'https://api/v1/orders', [], '{"a":1}');
        $request->getBody()->getContents(); // Move the stream pointer to the end, as a sent request would leave it.

        $exception = new class ('Could not resolve host.', $request) extends RuntimeException implements NetworkExceptionInterface {
            public function __construct(string $message, private readonly RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };

        $details = $this->createProcessor()->process(
            OperationContext::ApiCommunication,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            $exception
        );

        self::assertTrue($details->isNetworkError);
        self::assertNull($details->statusCode);
        self::assertSame('{"a":1}', $details->requestBody, 'The request body is rewound before reading.');
        self::assertSame(
            ['[API_NETWORK_ERROR] api_communication [Could not resolve host.]', '[API_ERROR] api_communication'],
            $this->debugMessages()
        );
        self::assertSame('{"a":1}', $this->sentErrors[0]->apiRequest);
    }

    public function testNoDebugLoggerSkipsDebugEventsButStillReports(): void
    {
        (new ApiErrorProcessor($this->createErrorLogger()))->process(
            OperationContext::ApiCommunication,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            new RuntimeException('Boom.')
        );

        self::assertSame([], $this->debugSink->records);
        self::assertCount(1, $this->sentErrors);
        self::assertNull($this->sentErrors[0]->apiRequestUrl);
    }

    public function testReportabilityHookSuppressesTheReportButKeepsDebugEvents(): void
    {
        $processor = new ApiErrorProcessor(
            $this->createErrorLogger(),
            DebugLogger::getInstance($this->tempLogFile),
            static fn (Throwable $e): bool => !$e instanceof ResponseValidationError
        );

        $processor->process(
            OperationContext::OrderCreation,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            new ResponseValidationError('Invalid response.')
        );

        self::assertSame([], $this->sentErrors);
        self::assertSame(['[API_ERROR] order_creation'], $this->debugMessages());
    }

    public function testSeverityHookOverridesTheCallerSeverity(): void
    {
        $processor = new ApiErrorProcessor(
            $this->createErrorLogger(),
            null,
            null,
            static fn (Throwable $e, ErrorSeverity $severity): ErrorSeverity => $e instanceof ConnectionTimeout
                ? ErrorSeverity::Warning
                : $severity
        );

        $processor->process(
            OperationContext::OrderCreation,
            ErrorCategory::ApiError,
            ErrorSeverity::Critical,
            new ConnectionTimeout('Timeout.')
        );

        self::assertSame(ErrorSeverity::Warning, $this->sentErrors[0]->severity);
    }

    public function testRecordedCallerIsTheCallSiteNotTheProcessor(): void
    {
        $this->createProcessor()->process(
            OperationContext::ApiCommunication,
            ErrorCategory::ApiError,
            ErrorSeverity::Error,
            new RuntimeException('Boom.')
        );

        self::assertStringContainsString(__FUNCTION__, (string) $this->sentErrors[0]->environment['caller']);
        self::assertStringContainsString(__FUNCTION__, (string) $this->debugSink->records[0]['context']['_caller']);
    }

    private function createProcessor(): ApiErrorProcessor
    {
        return new ApiErrorProcessor($this->createErrorLogger(), DebugLogger::getInstance($this->tempLogFile));
    }

    private function createErrorLogger(): ErrorLogger
    {
        return ErrorLogger::getInstance(
            $this->apiClient,
            $this->tempLogFile,
            'shop.example.com',
            'TestPlatform',
            '/var/www/module',
            ['plugin_version' => '1.0.0']
        );
    }

    /**
     * @return string[]
     */
    private function debugMessages(): array
    {
        return array_column($this->debugSink->records, 'message');
    }
}
