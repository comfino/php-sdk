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

use Comfino\Api\ClientInterface;
use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\Serializer\Json;
use Comfino\Backend\Queue\ReportErrorHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class ReportErrorHandlerTest extends TestCase
{
    private ClientInterface&MockObject $client;
    private Json $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->createMock(ClientInterface::class);
        $this->serializer = new Json();
    }

    private function makeError(): ShopPluginError
    {
        return new ShopPluginError(
            host: 'shop.example.com',
            platform: 'TestPlatform',
            pluginVersion: '1.0.0',
            platformVersion: '8.1',
            phpVersion: '8.2.0',
            category: ErrorCategory::ApiError,
            severity: ErrorSeverity::Error,
            context: OperationContext::ApiCommunication,
            errorCode: 'E_ERROR',
            errorMessage: 'Something went wrong',
            environment: ['php_version' => '8.2.0', 'plugin_version' => '1.0.0'],
            apiEndpoint: 'v1/some-endpoint',
            apiRequestUrl: 'https://api.comfino.pl/v1/some-endpoint',
            apiRequest: '{"key":"value"}',
            apiResponse: '{"error":"details"}',
            stackTrace: "#0 src/Foo.php(42): Bar->baz()\n#1 {main}",
            occurredAt: 1729683272
        );
    }

    public function testOperationTypeConstant(): void
    {
        self::assertSame('report_error', ReportErrorHandler::OPERATION_TYPE);
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testExecuteCallsSendLoggedErrorWithReconstructedDto(): void
    {
        $error = $this->makeError();
        $payload = $error->toQueuePayload($this->serializer);

        $captured = null;

        $this->client->expects($this->once())
            ->method('sendLoggedError')
            ->with($this->callback(static function (ShopPluginError $arg) use (&$captured): bool {
                $captured = $arg;

                return true;
            }));

        (new ReportErrorHandler($this->client, $this->serializer))->execute($payload);

        self::assertInstanceOf(ShopPluginError::class, $captured);
        self::assertSame($error->host, $captured->host);
        self::assertSame($error->platform, $captured->platform);
        self::assertSame($error->environment, $captured->environment);
        self::assertSame($error->errorCode, $captured->errorCode);
        self::assertSame($error->errorMessage, $captured->errorMessage);
        self::assertSame($error->apiRequestUrl, $captured->apiRequestUrl);
        self::assertSame($error->apiRequest, $captured->apiRequest);
        self::assertSame($error->apiResponse, $captured->apiResponse);
        self::assertSame($error->stackTrace, $captured->stackTrace);
    }

    public function testRoundTripPreservesNullableFieldsAsNull(): void
    {
        $error = new ShopPluginError(
            host: 'shop.example.com',
            platform: 'TestPlatform',
            pluginVersion: '1.0.0',
            platformVersion: '8.1',
            phpVersion: '8.2.0',
            category: ErrorCategory::Other,
            severity: ErrorSeverity::Error,
            context: OperationContext::Unknown,
            errorCode: '500',
            errorMessage: 'Internal error'
        );

        $reconstructed = ShopPluginError::fromQueuePayload(
            $error->toQueuePayload($this->serializer),
            $this->serializer
        );

        self::assertNull($reconstructed->apiRequestUrl);
        self::assertNull($reconstructed->apiRequest);
        self::assertNull($reconstructed->apiResponse);
        self::assertNull($reconstructed->stackTrace);
        self::assertSame('shop.example.com', $reconstructed->host);
        self::assertSame('500', $reconstructed->errorCode);
        self::assertSame('Internal error', $reconstructed->errorMessage);
    }

    public function testRoundTripPreservesEnvironmentArray(): void
    {
        $env = ['php_version' => '8.2.0', 'plugin_version' => '2.0', 'some_flag' => 'true'];
        $error = new ShopPluginError(
            'h',
            'P',
            '1.0.0',
            '8.1',
            '8.2.0',
            ErrorCategory::Other,
            ErrorSeverity::Error,
            OperationContext::Unknown,
            'E',
            'msg',
            $env
        );

        $reconstructed = ShopPluginError::fromQueuePayload(
            $error->toQueuePayload($this->serializer),
            $this->serializer
        );

        self::assertSame($env, $reconstructed->environment);
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testExecutePropagatesTransientExceptionFromClient(): void
    {
        $this->client->method('sendLoggedError')
            ->willThrowException(new ServiceUnavailable('Service is down'));

        $this->expectException(ServiceUnavailable::class);

        (new ReportErrorHandler($this->client, $this->serializer))
            ->execute($this->makeError()->toQueuePayload($this->serializer));
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testExecutePropagatesPermanentExceptionFromClient(): void
    {
        $this->client->method('sendLoggedError')
            ->willThrowException(new AuthorizationError('Invalid API key'));

        $this->expectException(AuthorizationError::class);

        (new ReportErrorHandler($this->client, $this->serializer))
            ->execute($this->makeError()->toQueuePayload($this->serializer));
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testExecutePropagatesGenericExceptionFromClient(): void
    {
        $this->client->method('sendLoggedError')
            ->willThrowException(new RuntimeException('Unexpected failure'));

        $this->expectException(RuntimeException::class);

        (new ReportErrorHandler($this->client, $this->serializer))
            ->execute($this->makeError()->toQueuePayload($this->serializer));
    }
}
