<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Webhook\Endpoint
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook\Endpoint;

use Comfino\Api\Exception\InvalidEndpoint;
use Comfino\Api\Exception\InvalidRequest;
use Comfino\Backend\Webhook\Endpoint\CacheInvalidate;
use Comfino\Enum\CacheItemType;
use JsonException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class CacheInvalidateTest extends TestCase
{
    /** @var CacheItemPoolInterface&TagAwareCacheInterface&MockObject */
    private CacheItemPoolInterface&TagAwareCacheInterface&MockObject $cache;
    private string $endpointName = 'cache-invalidate';
    private string $endpointUrl = '/api/cache-invalidate';

    protected function setUp(): void
    {
        parent::setUp();

        /** @phpstan-ignore-next-line PHPUnit 10.1+ createMockForIntersectionOfInterfaces() returns intersection type correctly */
        $this->cache = $this->createMockForIntersectionOfInterfaces([
            CacheItemPoolInterface::class,
            TagAwareCacheInterface::class,
        ]);
    }

    private function createEndpoint(): CacheInvalidate
    {
        return new CacheInvalidate($this->endpointName, $this->endpointUrl, $this->cache);
    }

    /**
     * @param array<mixed> $body
     *
     * @throws JsonException
     */
    private function createMockRequest(
        string $method = 'POST',
        string $uri = '/api/cache-invalidate',
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
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestThrowsInvalidEndpointWhenPathDoesNotMatch(): void
    {
        $request = $this->createMockRequest('POST', '/api/different-endpoint', []);

        $this->expectException(InvalidEndpoint::class);
        $this->expectExceptionMessage('Endpoint path does not match request path.');

        $this->createEndpoint()->processRequest($request);
    }

    /**
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestThrowsInvalidEndpointWhenEndpointNameDoesNotMatch(): void
    {
        $request = $this->createMockRequest('POST', '/api/different-url', []);

        $this->expectException(InvalidEndpoint::class);
        $this->expectExceptionMessage('Endpoint path does not match request path.');

        $this->createEndpoint()->processRequest($request, 'different-endpoint');
    }

    /**
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestPostMethodInvalidatesTags(): void
    {
        $tags = [CacheItemType::ADMIN_PRODUCT_TYPES->value, CacheItemType::ADMIN_WIDGET_TYPES->value];
        $this->cache->expects($this->once())->method('invalidateTags')->with($tags);

        $request = $this->createMockRequest('POST', $this->endpointUrl, $tags);

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    /**
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestPutMethodInvalidatesTags(): void
    {
        $tags = [CacheItemType::ADMIN_PRODUCT_TYPES->value];
        $this->cache->expects($this->once())->method('invalidateTags')->with($tags);

        $request = $this->createMockRequest('PUT', $this->endpointUrl, $tags);

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    /**
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestPatchMethodInvalidatesTags(): void
    {
        $tags = [CacheItemType::ADMIN_WIDGET_TYPES->value];
        $this->cache->expects($this->once())->method('invalidateTags')->with($tags);

        $request = $this->createMockRequest('PATCH', $this->endpointUrl, $tags);

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    /**
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestFiltersInvalidTags(): void
    {
        $requestPayload = [
            CacheItemType::ADMIN_PRODUCT_TYPES->value,
            'invalid_tag',
            CacheItemType::ADMIN_WIDGET_TYPES->value,
            'another_invalid_tag',
        ];
        // array_intersect preserves keys, so indices will be [0, 2]
        $validTags = [0 => CacheItemType::ADMIN_PRODUCT_TYPES->value, 2 => CacheItemType::ADMIN_WIDGET_TYPES->value];

        $this->cache->expects($this->once())->method('invalidateTags')->with($validTags);

        $request = $this->createMockRequest('POST', $this->endpointUrl, $requestPayload);

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    /**
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestWithEndpointNameMatch(): void
    {
        $tags = [CacheItemType::ADMIN_PRODUCT_TYPES->value];
        $this->cache->expects($this->once())->method('invalidateTags')->with($tags);

        $request = $this->createMockRequest('POST', $this->endpointUrl, $tags);

        $this->assertNull($this->createEndpoint()->processRequest($request, $this->endpointName));
    }

    /**
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestThrowsInvalidEndpointForGetMethod(): void
    {
        $request = $this->createMockRequest('GET', $this->endpointUrl, []);

        $this->expectException(InvalidEndpoint::class);
        $this->expectExceptionMessage('Endpoint path does not match request path.');

        $this->createEndpoint()->processRequest($request);
    }

    /**
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestThrowsInvalidEndpointForDeleteMethod(): void
    {
        $request = $this->createMockRequest('DELETE', $this->endpointUrl, []);

        $this->expectException(InvalidEndpoint::class);
        $this->expectExceptionMessage('Endpoint path does not match request path.');

        $this->createEndpoint()->processRequest($request);
    }

    /**
     * @throws InvalidArgumentException
     */
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

    /**
     * @throws InvalidArgumentException
     */
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
     * @throws InvalidArgumentException|JsonException
     */
    public function testProcessRequestSkipsInvalidationWhenCacheIsNotTagAware(): void
    {
        $tags = [CacheItemType::ADMIN_PRODUCT_TYPES->value];
        $cache = $this->createMock(CacheItemPoolInterface::class);

        $endpoint = new CacheInvalidate($this->endpointName, $this->endpointUrl, $cache);
        $request = $this->createMockRequest('POST', $this->endpointUrl, $tags);

        $this->assertNull($endpoint->processRequest($request));
    }
}
