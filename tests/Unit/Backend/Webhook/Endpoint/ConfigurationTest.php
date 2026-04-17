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
use Comfino\Api\SerializerInterface;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\Backend\Log\DebugLogger;
use Comfino\Backend\Webhook\Endpoint\Configuration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class ConfigurationTest extends TestCase
{
    private ConfigurationManager $configurationManager;
    private DebugLogger&MockObject $debugLogger;
    private StorageAdapterInterface&MockObject $storageAdapter;
    private SerializerInterface&MockObject $serializer;
    private string $endpointName = 'configuration';
    private string $endpointUrl = '/api/configuration';
    private string $platformName = 'TestPlatform';
    private string $platformVersion = '1.0.0';
    private string $pluginVersion = '2.0.0';
    private int $pluginBuildTs = 1638360000;
    private string $databaseVersion = '8.0.26';
    private int $debugLogNumLines = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageAdapter = $this->createMock(StorageAdapterInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->debugLogger = $this->createMock(DebugLogger::class);

        ConfigurationManager::reset();

        $this->storageAdapter->method('load')->willReturn([]);

        $this->configurationManager = ConfigurationManager::getInstance(
            ['api_key' => ConfigurationManager::OPT_VALUE_TYPE_STRING],
            ['api_key'],
            0,
            $this->storageAdapter,
            $this->serializer
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        ConfigurationManager::reset();
    }

    /** @param array<string, mixed>|null $shopExtraVariables */
    private function createEndpoint(?array $shopExtraVariables = null): Configuration
    {
        return new Configuration(
            $this->endpointName,
            $this->endpointUrl,
            $this->configurationManager,
            $this->debugLogger,
            $this->platformName,
            $this->platformVersion,
            $this->pluginVersion,
            $this->pluginBuildTs,
            $this->databaseVersion,
            $this->debugLogNumLines,
            $shopExtraVariables
        );
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $serverParams
     * @param array<string, mixed> $body
     */
    private function createMockRequest(
        string $method = 'GET',
        string $uri = '/api/configuration',
        array $queryParams = [],
        array $serverParams = [],
        array $body = [],
        string $contentType = ''
    ): ServerRequestInterface&MockObject {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);

        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn($uri);
        $request->method('getUri')->willReturn($uriMock);

        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getServerParams')->willReturn($serverParams);

        if (!empty($body) || $contentType !== '') {
            $bodyStream = $this->createMock(StreamInterface::class);
            $bodyStream->method('getContents')->willReturn(json_encode($body));
            $bodyStream->method('rewind');
            $request->method('getBody')->willReturn($bodyStream);
            $request->method('hasHeader')->with('Content-Type')->willReturn($contentType !== '');

            if ($contentType !== '') {
                $request->method('getHeader')->with('Content-Type')->willReturn([$contentType]);
            }
        }

        return $request;
    }

    public function testGetName(): void
    {
        $this->assertEquals($this->endpointName, $this->createEndpoint()->getName());
    }

    public function testGetMethods(): void
    {
        $this->assertEquals(['GET', 'POST', 'PUT', 'PATCH'], $this->createEndpoint()->getMethods());
    }

    public function testGetEndpointUrl(): void
    {
        $this->assertEquals($this->endpointUrl, $this->createEndpoint()->getEndpointUrl());
    }

    public function testProcessRequestThrowsInvalidEndpointWhenPathDoesNotMatch(): void
    {
        $request = $this->createMockRequest('GET', '/api/different-endpoint');

        $this->expectException(InvalidEndpoint::class);
        $this->expectExceptionMessage('Endpoint path does not match request path.');

        $this->createEndpoint()->processRequest($request);
    }

    public function testProcessRequestThrowsInvalidEndpointWhenEndpointNameDoesNotMatch(): void
    {
        $request = $this->createMockRequest('GET', '/api/different-url');

        $this->expectException(InvalidEndpoint::class);
        $this->expectExceptionMessage('Endpoint path does not match request path.');

        $this->createEndpoint()->processRequest($request, 'different-endpoint');
    }

    public function testProcessRequestGetMethodReturnsConfiguration(): void
    {
        $request = $this->createMockRequest(
            'GET',
            $this->endpointUrl,
            [],
            [
                'SERVER_SOFTWARE' => 'Apache/2.4.41',
                'SERVER_NAME' => 'localhost',
                'SERVER_ADDR' => '127.0.0.1',
            ]
        );

        $result = $this->createEndpoint()->processRequest($request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('shop_info', $result);
        $this->assertArrayHasKey('shop_configuration', $result);
        $this->assertEquals($this->platformName, $result['shop_info']['platform']);
        $this->assertEquals($this->platformVersion, $result['shop_info']['platform_version']);
        $this->assertEquals($this->pluginVersion, $result['shop_info']['plugin_version']);
        $this->assertEquals($this->pluginBuildTs, $result['shop_info']['plugin_build_ts']);
        $this->assertEquals($this->databaseVersion, $result['shop_info']['database_version']);
        $this->assertEquals(PHP_VERSION, $result['shop_info']['php_version']);
        $this->assertEquals('Apache/2.4.41', $result['shop_info']['server_software']);
        $this->assertEquals('localhost', $result['shop_info']['server_name']);
        $this->assertEquals('127.0.0.1', $result['shop_info']['server_addr']);
    }

    public function testProcessRequestGetMethodReturnsDebugLog(): void
    {
        $debugLogContent = "Log line 1\nLog line 2\nLog line 3";

        $this->debugLogger->method('getDebugLog')->with($this->debugLogNumLines)->willReturn($debugLogContent);

        $request = $this->createMockRequest(
            'GET',
            $this->endpointUrl,
            ['responseType' => 'debug_log']
        );

        $result = $this->createEndpoint()->processRequest($request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('debug_log', $result);
        $this->assertEquals($debugLogContent, $result['debug_log']);
    }

    public function testProcessRequestGetMethodWithWordPressVersion(): void
    {
        $shopExtraVariables = ['wordpress_version' => '6.0.0', 'theme' => 'twentytwentyone'];

        $request = $this->createMockRequest(
            'GET',
            $this->endpointUrl,
            [],
            [
                'SERVER_SOFTWARE' => 'nginx',
                'SERVER_NAME' => 'example.com',
                'SERVER_ADDR' => '192.168.1.1',
            ]
        );

        $result = $this->createEndpoint($shopExtraVariables)->processRequest($request);

        $this->assertIsArray($result);
        $this->assertEquals('6.0.0', $result['shop_info']['wordpress_version']);
        $this->assertArrayHasKey('extra_variables', $result['shop_info']);
        $this->assertArrayHasKey('theme', $result['shop_info']['extra_variables']);
        $this->assertArrayNotHasKey('wordpress_version', $result['shop_info']['extra_variables']);
    }

    public function testProcessRequestGetMethodWithoutWordPressVersion(): void
    {
        $shopExtraVariables = ['theme' => 'twentytwentyone'];

        $request = $this->createMockRequest(
            'GET',
            $this->endpointUrl,
            [],
            [
                'SERVER_SOFTWARE' => 'nginx',
                'SERVER_NAME' => 'example.com',
                'SERVER_ADDR' => '192.168.1.1',
            ]
        );

        $result = $this->createEndpoint($shopExtraVariables)->processRequest($request);

        $this->assertIsArray($result);
        $this->assertEquals('n/a', $result['shop_info']['wordpress_version']);
    }

    public function testProcessRequestGetMethodWithoutExtraVariables(): void
    {
        $request = $this->createMockRequest(
            'GET',
            $this->endpointUrl,
            [],
            [
                'SERVER_SOFTWARE' => 'nginx',
                'SERVER_NAME' => 'example.com',
                'SERVER_ADDR' => '192.168.1.1',
            ]
        );

        $result = $this->createEndpoint()->processRequest($request);

        $this->assertIsArray($result);
        $this->assertEquals('n/a', $result['shop_info']['wordpress_version']);
        $this->assertNull($result['shop_info']['extra_variables']);
    }

    public function testProcessRequestPostMethodUpdatesConfiguration(): void
    {
        $configPayload = ['api_key' => 'new_key', 'enabled' => false];
        $request = $this->createMockRequest(
            'POST',
            $this->endpointUrl,
            [],
            [],
            $configPayload,
            'application/json'
        );

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    public function testProcessRequestPutMethodUpdatesConfiguration(): void
    {
        $configPayload = ['timeout' => 30];
        $request = $this->createMockRequest(
            'PUT',
            $this->endpointUrl,
            [],
            [],
            $configPayload,
            'application/json'
        );

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    public function testProcessRequestPatchMethodUpdatesConfiguration(): void
    {
        $configPayload = ['debug_mode' => true];
        $request = $this->createMockRequest(
            'PATCH',
            $this->endpointUrl,
            [],
            [],
            $configPayload,
            'application/json'
        );

        $this->assertNull($this->createEndpoint()->processRequest($request));
    }

    public function testProcessRequestThrowsInvalidRequestWhenBodyIsNotArray(): void
    {
        $endpoint = $this->createEndpoint();
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

        $endpoint->processRequest($request);
    }

    public function testProcessRequestThrowsInvalidRequestOnJsonException(): void
    {
        $endpoint = $this->createEndpoint();
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

        $endpoint->processRequest($request);
    }

    public function testProcessRequestWithEndpointNameMatch(): void
    {
        $request = $this->createMockRequest(
            'GET',
            $this->endpointUrl,
            [],
            [
                'SERVER_SOFTWARE' => 'nginx',
                'SERVER_NAME' => 'example.com',
                'SERVER_ADDR' => '192.168.1.1',
            ]
        );

        $result = $this->createEndpoint()->processRequest($request, $this->endpointName);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('shop_info', $result);
        $this->assertArrayHasKey('shop_configuration', $result);
    }

    public function testProcessRequestGetMethodWithSymfonyKernel(): void
    {
        $request = $this->createMockRequest(
            'GET',
            $this->endpointUrl,
            [],
            [
                'SERVER_SOFTWARE' => 'nginx',
                'SERVER_NAME' => 'example.com',
                'SERVER_ADDR' => '192.168.1.1',
            ]
        );

        $result = $this->createEndpoint()->processRequest($request);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('symfony_version', $result['shop_info']);
        $this->assertEquals('n/a', $result['shop_info']['symfony_version']);
    }
}
