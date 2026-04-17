<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Webhook
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook;

use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Api\Exception\InvalidRequest;
use Comfino\Api\SerializerInterface;
use Comfino\Backend\Webhook\RateLimiterInterface;
use Comfino\Backend\Webhook\ReplayProtectionInterface;
use Comfino\Backend\Webhook\WebhookEndpointInterface;
use Comfino\Backend\Webhook\WebhookManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;

final class WebhookManagerTest extends TestCase
{
    private ServerRequestFactoryInterface&MockObject $serverRequestFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private UriFactoryInterface&MockObject $uriFactory;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private SerializerInterface&MockObject $serializer;
    private string $platformName = 'TestPlatform';
    private string $platformVersion = '1.0.0';
    private string $pluginVersion = '2.0.0';
    /** @var string[] */
    private array $apiKeys = ['test_api_key_123'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverRequestFactory = $this->createMock(ServerRequestFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->uriFactory = $this->createMock(UriFactoryInterface::class);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
    }

    /**
     * @param string[]|null $apiKeys
     */
    private function createManager(
        ?array $apiKeys = null,
        ?ReplayProtectionInterface $replayProtection = null,
        ?RateLimiterInterface $rateLimiter = null
    ): WebhookManager {
        return new WebhookManager(
            $this->platformName,
            $this->platformVersion,
            $this->pluginVersion,
            $apiKeys ?? $this->apiKeys,
            $this->serverRequestFactory,
            $this->streamFactory,
            $this->uriFactory,
            $this->responseFactory,
            $this->serializer,
            $replayProtection,
            $rateLimiter
        );
    }

    /** @param string[] $methods */
    private function createMockEndpoint(string $name, string $url, array $methods): WebhookEndpointInterface&MockObject
    {
        $endpoint = $this->createMock(WebhookEndpointInterface::class);
        $endpoint->method('getName')->willReturn($name);
        $endpoint->method('getEndpointUrl')->willReturn($url);
        $endpoint->method('getMethods')->willReturn($methods);
        $endpoint->expects($this->once())->method('setSerializer')->with($this->serializer);

        return $endpoint;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, string> $headers
     */
    private function createMockRequest(
        string $method = 'GET',
        array $queryParams = [],
        array $headers = [],
        string $body = ''
    ): ServerRequestInterface&MockObject {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getQueryParams')->willReturn($queryParams);

        // Set up signature headers.
        if (isset($headers['CR-Signature'])) {
            $request->method('hasHeader')->willReturnCallback(fn ($h) => $h === 'CR-Signature');
            $request->method('getHeader')->willReturnCallback(
                fn ($h) => $h === 'CR-Signature' ? [$headers['CR-Signature']] : []
            );
        } elseif (isset($headers['X-CR-Signature'])) {
            $request->method('hasHeader')->willReturnCallback(fn ($h) => $h === 'X-CR-Signature');
            $request->method('getHeader')->willReturnCallback(
                fn ($h) => $h === 'X-CR-Signature' ? [$headers['X-CR-Signature']] : []
            );
        } else {
            $request->method('hasHeader')->willReturn(false);
        }

        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('getContents')->willReturn($body);
        $bodyStream->method('rewind');
        $request->method('getBody')->willReturn($bodyStream);

        return $request;
    }

    private function createMockResponse(): ResponseInterface&MockObject
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        return $response;
    }

    public function testRegisterEndpoint(): void
    {
        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['GET']);

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint);

        $this->assertSame($endpoint, $manager->getEndpointByName('test-endpoint'));
    }

    public function testGetEndpointByNameReturnsNullForUnknownEndpoint(): void
    {
        $this->assertNull($this->createManager()->getEndpointByName('unknown-endpoint'));
    }

    public function testGetRegisteredEndpointsReturnsMetadata(): void
    {
        $endpoint1 = $this->createMockEndpoint('endpoint1', '/api/endpoint1', ['GET', 'POST']);
        $endpoint2 = $this->createMockEndpoint('endpoint2', '/api/endpoint2', ['PUT']);

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint1);
        $manager->registerEndpoint($endpoint2);

        $endpoints = $manager->getRegisteredEndpoints();

        $this->assertGreaterThanOrEqual(1, count($endpoints));

        foreach ($endpoints as $endpointData) {
            $this->assertArrayHasKey('url', $endpointData);
            $this->assertArrayHasKey('methods', $endpointData);
        }
    }

    public function testProcessRequestThrowsAuthorizationErrorWhenNoSignature(): void
    {
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(401, 'Unauthorized request.')->willReturn($response);

        $this->assertSame($response, $this->createManager()->processRequest(null, $this->createMockRequest()));
    }

    public function testProcessRequestThrowsAuthorizationErrorWhenNoVkeyForGetRequest(): void
    {
        $request = $this->createMockRequest('GET', [], ['CR-Signature' => 'test_signature']);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(401, 'Unauthorized request.')->willReturn($response);

        $this->assertSame($response, $this->createManager()->processRequest(null, $request));
    }

    public function testProcessRequestThrowsAccessDeniedWhenSignatureDoesNotMatch(): void
    {
        $request = $this->createMockRequest('GET', ['vkey' => 'test_vkey'], ['CR-Signature' => 'invalid_signature']);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')
            ->with(403, 'Access not allowed. Failed comparison of CR-Signature and shop hash.')
            ->willReturn($response);

        $this->assertSame($response, $this->createManager()->processRequest(null, $request));
    }

    public function testProcessRequestSucceedsWithValidGetSignature(): void
    {
        $validationKey = 'test_vkey';
        $expectedSignature = hash('sha3-256', $this->apiKeys[0] . $validationKey);

        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['GET']);
        $endpoint->method('processRequest')->willReturn(['result' => 'success']);

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('GET', ['vkey' => $validationKey], ['CR-Signature' => $expectedSignature]);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(200, 'OK')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);

        $this->streamFactory->method('createStream')->with('{"result":"success"}')->willReturn($stream);
        $this->serializer->method('serialize')->with(['result' => 'success'])->willReturn('{"result":"success"}');

        $manager->processRequest('test-endpoint', $request);

        $this->assertEquals($expectedSignature, $manager->getCalculatedCrSignature());
        $this->assertEquals($expectedSignature, $manager->getReceivedCrSignature());
    }

    public function testProcessRequestSucceedsWithValidPostSignature(): void
    {
        $requestBody = '{"data":"test"}';
        $expectedSignature = hash('sha3-256', $this->apiKeys[0] . $requestBody);

        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['POST']);
        $endpoint->method('processRequest')->willReturn(['result' => 'created']);

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('POST', [], ['CR-Signature' => $expectedSignature], $requestBody);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(201, 'Created')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);

        $this->streamFactory->method('createStream')->with('{"result":"created"}')->willReturn($stream);
        $this->serializer->method('serialize')->with(['result' => 'created'])->willReturn('{"result":"created"}');

        $manager->processRequest('test-endpoint', $request);
    }

    public function testProcessRequestSupportsXCrSignatureHeader(): void
    {
        $validationKey = 'test_vkey';
        $expectedSignature = hash('sha3-256', $this->apiKeys[0] . $validationKey);

        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['GET']);
        $endpoint->method('processRequest')->willReturn(['result' => 'success']);

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest(
            'GET',
            ['vkey' => $validationKey],
            ['X-CR-Signature' => $expectedSignature]
        );
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(200, 'OK')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);

        $this->streamFactory->method('createStream')->with('{"result":"success"}')->willReturn($stream);
        $this->serializer->method('serialize')->with(['result' => 'success'])->willReturn('{"result":"success"}');

        $manager->processRequest('test-endpoint', $request);
    }

    public function testProcessRequestReturns404WhenNoEndpointMatches(): void
    {
        $validationKey = 'test_vkey';
        $expectedSignature = hash('sha3-256', $this->apiKeys[0] . $validationKey);

        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['GET']);
        $endpoint->method('processRequest')->willThrowException(new InvalidEndpoint('Endpoint not found'));

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('GET', ['vkey' => $validationKey], ['CR-Signature' => $expectedSignature]);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(404, 'Endpoint not found.')->willReturn($response);

        $manager->processRequest(null, $request);
    }

    public function testProcessRequestReturns400OnInvalidRequest(): void
    {
        $validationKey = 'test_vkey';
        $expectedSignature = hash('sha3-256', $this->apiKeys[0] . $validationKey);

        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['GET']);
        $endpoint->method('processRequest')
            ->willThrowException(new InvalidRequest('/api/test', '', 'Invalid request data'));

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest(
            'GET',
            ['vkey' => $validationKey],
            ['CR-Signature' => $expectedSignature]
        );
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(400, 'Invalid request data')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);

        $this->streamFactory->method('createStream')->with('{"error":"Invalid request data"}')->willReturn($stream);
        $this->serializer->method('serialize')->with(['error' => 'Invalid request data'])
            ->willReturn('{"error":"Invalid request data"}');

        $manager->processRequest('test-endpoint', $request);
    }

    public function testProcessRequestSupportsMultipleApiKeys(): void
    {
        $apiKeys = ['key1', 'key2', 'key3'];
        $validationKey = 'test_vkey';
        $expectedSignature = hash('sha3-256', $apiKeys[1] . $validationKey);

        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['GET']);
        $endpoint->method('processRequest')->willReturn(['result' => 'success']);

        $manager = $this->createManager($apiKeys);
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('GET', ['vkey' => $validationKey], ['CR-Signature' => $expectedSignature]);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(200, 'OK')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);

        $this->streamFactory->method('createStream')->with('{"result":"success"}')->willReturn($stream);
        $this->serializer->method('serialize')->with(['result' => 'success'])->willReturn('{"result":"success"}');

        $manager->processRequest('test-endpoint', $request);
    }

    public function testProcessRequestReturnsCorrectStatusForPutWithoutBody(): void
    {
        $requestBody = '{"data":"update"}';
        $expectedSignature = hash('sha3-256', $this->apiKeys[0] . $requestBody);

        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['PUT']);
        $endpoint->method('processRequest')->willReturn(null);

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('PUT', [], ['CR-Signature' => $expectedSignature], $requestBody);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(204, 'No content')->willReturn($response);

        $manager->processRequest('test-endpoint', $request);
    }

    public function testProcessRequestReturnsCorrectStatusForPatchWithEmptyBody(): void
    {
        $requestBody = '{"data":"patch"}';
        $expectedSignature = hash('sha3-256', $this->apiKeys[0] . $requestBody);

        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['PATCH']);
        $endpoint->method('processRequest')->willReturn([]);

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('PATCH', [], ['CR-Signature' => $expectedSignature], $requestBody);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(204, 'No content')->willReturn($response);

        $manager->processRequest('test-endpoint', $request);
    }

    public function testGetReceivedCrSignatureReturnsNullInitially(): void
    {
        $this->assertNull($this->createManager()->getReceivedCrSignature());
    }

    public function testGetCalculatedCrSignatureReturnsNullInitially(): void
    {
        $this->assertNull($this->createManager()->getCalculatedCrSignature());
    }

    public function testSignaturePropertiesSetAfterVerification(): void
    {
        $validationKey = 'test_vkey';
        $expectedSignature = hash('sha3-256', $this->apiKeys[0] . $validationKey);

        $endpoint = $this->createMockEndpoint('test-endpoint', '/api/test', ['GET']);
        $endpoint->method('processRequest')->willReturn([]);

        $manager = $this->createManager();
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('GET', ['vkey' => $validationKey], ['CR-Signature' => $expectedSignature]);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(200, 'OK')->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);

        $this->streamFactory->method('createStream')->with('[]')->willReturn($stream);
        $this->serializer->method('serialize')->with([])->willReturn('[]');

        $manager->processRequest('test-endpoint', $request);

        $this->assertEquals($expectedSignature, $manager->getReceivedCrSignature());
        $this->assertEquals($expectedSignature, $manager->getCalculatedCrSignature());
    }

    public function testReplayProtectionBlocksDuplicateRequest(): void
    {
        $validationKey = 'test_vkey';
        $signature = hash('sha3-256', $this->apiKeys[0] . $validationKey);

        $replayProtection = $this->createMock(ReplayProtectionInterface::class);
        $replayProtection->method('isDuplicate')->with($signature)->willReturn(true);
        $replayProtection->expects($this->never())->method('markProcessed');

        $endpoint = $this->createMockEndpoint('ep', '/api/ep', ['GET']);
        $endpoint->expects($this->never())->method('processRequest');

        $manager = $this->createManager(replayProtection: $replayProtection);
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('GET', ['vkey' => $validationKey], ['CR-Signature' => $signature]);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(403, 'Duplicate webhook request.')
            ->willReturn($response);

        $this->assertSame($response, $manager->processRequest('ep', $request));
    }

    public function testReplayProtectionMarksProcessedOnlyAfterSuccess(): void
    {
        $validationKey = 'test_vkey';
        $signature = hash('sha3-256', $this->apiKeys[0] . $validationKey);

        $replayProtection = $this->createMock(ReplayProtectionInterface::class);
        $replayProtection->method('isDuplicate')->willReturn(false);
        $replayProtection->expects($this->once())->method('markProcessed')->with($signature);

        $endpoint = $this->createMockEndpoint('ep', '/api/ep', ['GET']);
        $endpoint->method('processRequest')->willReturn([]);

        $manager = $this->createManager(replayProtection: $replayProtection);
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('GET', ['vkey' => $validationKey], ['CR-Signature' => $signature]);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(200, 'OK')->willReturn($response);
        $this->streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));
        $this->serializer->method('serialize')->willReturn('[]');

        $manager->processRequest('ep', $request);
    }

    public function testReplayProtectionDoesNotMarkProcessedWhenEndpointThrows(): void
    {
        $requestBody = '{"data":"test"}';
        $signature = hash('sha3-256', $this->apiKeys[0] . $requestBody);

        $replayProtection = $this->createMock(ReplayProtectionInterface::class);
        $replayProtection->method('isDuplicate')->willReturn(false);
        $replayProtection->expects($this->never())->method('markProcessed');

        $endpoint = $this->createMockEndpoint('ep', '/api/ep', ['POST']);
        $endpoint->method('processRequest')->willThrowException(new InvalidRequest('bad payload'));

        $manager = $this->createManager(replayProtection: $replayProtection);
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('POST', [], ['CR-Signature' => $signature], $requestBody);
        $errorResponse = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->willReturn($errorResponse);

        $manager->processRequest('ep', $request);

        // Assertion is implicit: markProcessed mock expectation set to never() above.
        $this->addToAssertionCount(1);
    }

    public function testRateLimiterBlocksRequestWithCorrectEndpointName(): void
    {
        $validationKey = 'test_vkey';
        $signature = hash('sha3-256', $this->apiKeys[0] . $validationKey);

        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('isAllowed')
            ->with('my-endpoint', $this->anything())
            ->willReturn(false);

        $endpoint = $this->createMockEndpoint('my-endpoint', '/api/my-endpoint', ['GET']);
        $endpoint->expects($this->never())->method('processRequest');

        $manager = $this->createManager(rateLimiter: $rateLimiter);
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('GET', ['vkey' => $validationKey], ['CR-Signature' => $signature]);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(429, 'Rate limit exceeded.')->willReturn($response);

        $this->assertSame($response, $manager->processRequest('my-endpoint', $request));
    }

    public function testRateLimiterAllowsRequestWhenNotExceeded(): void
    {
        $validationKey = 'test_vkey';
        $signature = hash('sha3-256', $this->apiKeys[0] . $validationKey);

        $rateLimiter = $this->createMock(RateLimiterInterface::class);
        $rateLimiter->expects($this->once())
            ->method('isAllowed')
            ->with('ep', $this->anything())
            ->willReturn(true);

        $endpoint = $this->createMockEndpoint('ep', '/api/ep', ['GET']);
        $endpoint->expects($this->once())->method('processRequest')->willReturn([]);

        $manager = $this->createManager(rateLimiter: $rateLimiter);
        $manager->registerEndpoint($endpoint);

        $request = $this->createMockRequest('GET', ['vkey' => $validationKey], ['CR-Signature' => $signature]);
        $response = $this->createMockResponse();

        $this->responseFactory->method('createResponse')->with(200, 'OK')->willReturn($response);
        $this->streamFactory->method('createStream')->willReturn($this->createMock(StreamInterface::class));
        $this->serializer->method('serialize')->willReturn('[]');

        $manager->processRequest('ep', $request);
    }
}
