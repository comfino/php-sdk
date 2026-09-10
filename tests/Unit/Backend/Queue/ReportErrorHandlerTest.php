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
use Comfino\Backend\Queue\TenantAwareRetryableOperationHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use stdClass;

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

        $reconstructed = ShopPluginError::fromQueuePayload($error->toQueuePayload($this->serializer), $this->serializer);

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

        $reconstructed = ShopPluginError::fromQueuePayload($error->toQueuePayload($this->serializer), $this->serializer);

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

    // --- per-tenant credential resolution ---

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testClientFactoryResolvesTheClientForTheRequestsTenant(): void
    {
        /* The defect: with a single injected client, a report enqueued by tenant-b is delivered on the drain with
           whatever key the ambient (cron-scope) client holds, gets a 401, and is dropped as a permanent failure. */
        $tenantClient = $this->createMock(ClientInterface::class);
        $tenantClient->expects($this->once())->method('sendLoggedError');

        $this->client->expects($this->never())->method('sendLoggedError');

        $seenTenants = [];
        $handler = new ReportErrorHandler(
            function (?string $tenantKey) use (&$seenTenants, $tenantClient): ClientInterface {
                $seenTenants[] = $tenantKey;

                return $tenantKey === 'tenant-b' ? $tenantClient : $this->client;
            },
            $this->serializer
        );

        $handler->executeForTenant($this->makeError()->toQueuePayload($this->serializer), 'tenant-b');

        self::assertSame(['tenant-b'], $seenTenants);
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testClientFactoryReceivesNullForRequestsCarryingNoTenantIdentity(): void
    {
        $seenTenants = [];
        $handler = new ReportErrorHandler(
            function (?string $tenantKey) use (&$seenTenants): ClientInterface {
                $seenTenants[] = $tenantKey;

                return $this->client;
            },
            $this->serializer
        );

        $handler->execute($this->makeError()->toQueuePayload($this->serializer));

        self::assertSame([null], $seenTenants);
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testAmbientClientIsUsedForEveryTenantWhenNoFactoryIsGiven(): void
    {
        // Single-tenant integrations keep the pre-3.2 behavior: one client, ambient credentials, no factory needed.
        $this->client->expects($this->once())->method('sendLoggedError');

        (new ReportErrorHandler($this->client, $this->serializer))
            ->executeForTenant($this->makeError()->toQueuePayload($this->serializer), 'tenant-b');
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testClientFactoryReconstructsTheSameDtoAsTheDirectClientPath(): void
    {
        $error = $this->makeError();
        $captured = null;

        $this->client->expects($this->once())
            ->method('sendLoggedError')
            ->with($this->callback(static function (ShopPluginError $arg) use (&$captured): bool {
                $captured = $arg;

                return true;
            }));

        $handler = new ReportErrorHandler(fn (?string $tenantKey): ClientInterface => $this->client, $this->serializer);
        $handler->executeForTenant($error->toQueuePayload($this->serializer), 'tenant-a');

        self::assertInstanceOf(ShopPluginError::class, $captured);
        self::assertSame($error->host, $captured->host);
        self::assertSame($error->errorMessage, $captured->errorMessage);
        self::assertSame($error->environment, $captured->environment);
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testClientFactoryFailureIsPropagatedSoTheQueueCanRetryIt(): void
    {
        /* A store mid-configuration-edit must not cost the report: the queue classifies this as transient and retries,
           dead-lettering only once the attempt budget runs out. */
        $handler = new ReportErrorHandler(
            static function (?string $tenantKey): ClientInterface {
                throw new RuntimeException(sprintf('No credentials configured for tenant "%s".', $tenantKey ?? '-'));
            },
            $this->serializer
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No credentials configured for tenant "tenant-b".');

        $handler->executeForTenant($this->makeError()->toQueuePayload($this->serializer), 'tenant-b');
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testClientFactoryReturningSomethingElseThrows(): void
    {
        $brokenFactory = static fn (?string $tenantKey): stdClass => new stdClass();

        /** @phpstan-ignore argument.type (the point of the test: a factory that breaks the declared contract) */
        $handler = new ReportErrorHandler($brokenFactory, $this->serializer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a');

        $handler->executeForTenant($this->makeError()->toQueuePayload($this->serializer), 'tenant-b');
    }

    public function testHandlerIsTenantAware(): void
    {
        self::assertInstanceOf(TenantAwareRetryableOperationHandlerInterface::class, new ReportErrorHandler($this->client, $this->serializer));
    }
}
