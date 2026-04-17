<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Webhook\Endpoint
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook\Endpoint;

use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Api\Exception\InvalidRequest;
use Comfino\Backend\Webhook\Endpoint\StatusNotification;
use Comfino\Shop\Order\StatusManager;
use JsonException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class StatusNotificationTest extends TestCase
{
    private StatusManager&MockObject $statusManager;
    private string $endpointName = 'status-notification';
    private string $endpointUrl = '/api/status-notification';
    /** @var string[] */
    private array $forbiddenStatuses = ['CANCELLED', 'REJECTED'];
    /** @var string[] */
    private array $ignoredStatuses = ['WAITING_FOR_PAYMENT'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->statusManager = $this->createMock(StatusManager::class);
    }

    private function createEndpoint(): StatusNotification
    {
        return new StatusNotification(
            $this->endpointName,
            $this->endpointUrl,
            $this->statusManager,
            $this->forbiddenStatuses,
            $this->ignoredStatuses
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws JsonException
     */
    private function createMockRequest(
        string $method = 'POST',
        string $uri = '/api/status-notification',
        array $body = [],
        string $contentType = 'application/json'
    ): ServerRequestInterface&MockObject {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);

        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn($uri);
        $request->method('getUri')->willReturn($uriMock);

        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('getContents')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        $bodyStream->method('rewind');
        $request->method('getBody')->willReturn($bodyStream);
        $request->method('hasHeader')->with('Content-Type')->willReturn($contentType !== '');

        if ($contentType !== '') {
            $request->method('getHeader')->with('Content-Type')->willReturn([$contentType]);
        }

        return $request;
    }

    public function testGetName(): void
    {
        $this->assertEquals($this->endpointName, $this->createEndpoint()->getName());
    }

    public function testGetMethods(): void
    {
        $this->assertEquals(['POST', 'PUT', 'PATCH'], $this->createEndpoint()->getMethods());
    }

    public function testGetEndpointUrl(): void
    {
        $this->assertEquals($this->endpointUrl, $this->createEndpoint()->getEndpointUrl());
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestThrowsInvalidEndpointWhenPathDoesNotMatch(): void
    {
        $request = $this->createMockRequest('POST', '/api/different-endpoint');

        $this->expectException(InvalidEndpoint::class);
        $this->expectExceptionMessage('Endpoint path does not match request path.');

        $this->createEndpoint()->processRequest($request);
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestThrowsInvalidEndpointWhenEndpointNameDoesNotMatch(): void
    {
        $request = $this->createMockRequest('POST', '/api/different-url');

        $this->expectException(InvalidEndpoint::class);
        $this->expectExceptionMessage('Endpoint path does not match request path.');

        $this->createEndpoint()->processRequest($request, 'different-endpoint');
    }

    public function testProcessRequestThrowsInvalidRequestWhenBodyIsNotArray(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');

        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn($this->endpointUrl);
        $request->method('getUri')->willReturn($uriMock);

        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('getContents')->willReturn('"string_body"');
        $bodyStream->method('rewind');
        $request->method('getBody')->willReturn($bodyStream);

        $request->method('hasHeader')->with('Content-Type')->willReturn(true);
        $request->method('getHeader')->with('Content-Type')->willReturn(['application/json']);

        $this->expectException(InvalidRequest::class);
        $this->expectExceptionMessage('Invalid request payload.');

        $this->createEndpoint()->processRequest($request);
    }

    public function testProcessRequestThrowsInvalidRequestOnJsonException(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');

        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn($this->endpointUrl);
        $request->method('getUri')->willReturn($uriMock);

        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('getContents')->willReturn('invalid json{');
        $bodyStream->method('rewind');
        $request->method('getBody')->willReturn($bodyStream);

        $request->method('hasHeader')->with('Content-Type')->willReturn(true);
        $request->method('getHeader')->with('Content-Type')->willReturn(['application/json']);

        $this->expectException(InvalidRequest::class);
        $this->expectExceptionMessageMatches('/Invalid request payload:/');

        $this->createEndpoint()->processRequest($request);
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestThrowsInvalidRequestWhenStatusNotSet(): void
    {
        $request = $this->createMockRequest('POST', $this->endpointUrl, ['externalId' => '12345']);

        $this->expectException(InvalidRequest::class);
        $this->expectExceptionMessage('Status must be set.');

        $this->createEndpoint()->processRequest($request);
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestReturnsNullForIgnoredStatus(): void
    {
        $request = $this->createMockRequest(
            'POST',
            $this->endpointUrl,
            ['status' => 'WAITING_FOR_PAYMENT', 'externalId' => '12345']
        );

        $this->statusManager->expects($this->never())->method('setOrderStatus');

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestThrowsInvalidRequestWhenExternalIdNotSet(): void
    {
        $request = $this->createMockRequest('POST', $this->endpointUrl, ['status' => 'ACCEPTED']);

        $this->expectException(InvalidRequest::class);
        $this->expectExceptionMessage('External ID must be set.');

        $this->createEndpoint()->processRequest($request);
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestThrowsInvalidRequestForForbiddenStatus(): void
    {
        $endpoint = $this->createEndpoint();
        $request = $this->createMockRequest(
            'POST',
            $this->endpointUrl,
            ['status' => 'CANCELLED', 'externalId' => '12345']
        );

        $this->expectException(InvalidRequest::class);
        $this->expectExceptionMessage('Invalid status "CANCELLED".');

        $endpoint->processRequest($request);
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestUpdatesOrderStatusSuccessfully(): void
    {
        $externalId = '12345';
        $status = 'ACCEPTED';

        $request = $this->createMockRequest(
            'POST',
            $this->endpointUrl,
            ['status' => $status, 'externalId' => $externalId]
        );

        $this->statusManager->expects($this->once())->method('setOrderStatus')->with($externalId, $status);

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestWithPutMethod(): void
    {
        $externalId = '54321';
        $status = 'PAID';

        $request = $this->createMockRequest(
            'PUT',
            $this->endpointUrl,
            ['status' => $status, 'externalId' => $externalId]
        );

        $this->statusManager->expects($this->once())->method('setOrderStatus')->with($externalId, $status);

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestWithPatchMethod(): void
    {
        $externalId = '99999';
        $status = 'COMPLETED';

        $request = $this->createMockRequest(
            'PATCH',
            $this->endpointUrl,
            ['status' => $status, 'externalId' => $externalId]
        );

        $this->statusManager->expects($this->once())->method('setOrderStatus')->with($externalId, $status);

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestThrowsInvalidRequestWhenStatusManagerThrowsException(): void
    {
        $request = $this->createMockRequest(
            'POST',
            $this->endpointUrl,
            ['status' => 'ACCEPTED', 'externalId' => '12345']
        );

        $this->statusManager->method('setOrderStatus')->willThrowException(new RuntimeException('Order not found'));

        $this->expectException(InvalidRequest::class);
        $this->expectExceptionMessage('Order not found');

        $this->createEndpoint()->processRequest($request);
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestWithEndpointNameMatch(): void
    {
        $endpoint = $this->createEndpoint();
        $externalId = '67890';
        $status = 'SHIPPED';

        $request = $this->createMockRequest(
            'POST',
            $this->endpointUrl,
            ['status' => $status, 'externalId' => $externalId]
        );

        $this->statusManager->expects($this->once())->method('setOrderStatus')->with($externalId, $status);

        $this->assertNull($endpoint->processRequest($request, $this->endpointName));
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestValidatesMultipleForbiddenStatuses(): void
    {
        $request = $this->createMockRequest(
            'POST',
            $this->endpointUrl,
            ['status' => 'REJECTED', 'externalId' => '12345']
        );

        $this->expectException(InvalidRequest::class);
        $this->expectExceptionMessage('Invalid status "REJECTED".');

        $this->createEndpoint()->processRequest($request);
    }

    /**
     * @throws JsonException
     */
    public function testProcessRequestHandlesAdditionalPayloadFields(): void
    {
        $externalId = '12345';
        $status = 'ACCEPTED';

        $request = $this->createMockRequest('POST', $this->endpointUrl, [
            'status' => $status,
            'externalId' => $externalId,
            'additionalField1' => 'value1',
            'additionalField2' => 'value2',
        ]);

        $this->statusManager->expects($this->once())->method('setOrderStatus')->with($externalId, $status);

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }
}
