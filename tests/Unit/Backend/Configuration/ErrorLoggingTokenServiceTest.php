<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Configuration;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Api\ClientInterface;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Request\ClaimErrorLoggingToken as ClaimErrorLoggingTokenRequest;
use Comfino\Api\Response\ClaimErrorLoggingToken as ClaimErrorLoggingTokenResponse;
use Comfino\Api\Serializer\Json;
use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\ErrorLoggingTokenService;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use JsonException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ErrorLoggingTokenServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private ClientInterface&MockObject $apiClient;
    /** @var array<string, mixed> */
    private array $savedOptions = [];

    protected function setUp(): void
    {
        parent::setUp();

        ConfigurationManager::reset();

        $this->apiClient = $this->createMock(ClientInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        ConfigurationManager::reset();
    }

    public function testEmptyApiKeyNeverClaims(): void
    {
        $this->apiClient->expects($this->never())->method('claimErrorLoggingToken');

        self::assertFalse($this->createService([])->refreshIfNeeded(''));
    }

    public function testValidTokenIsNotRefreshed(): void
    {
        $this->apiClient->expects($this->never())->method('claimErrorLoggingToken');

        $service = $this->createService([
            ErrorLoggingTokenService::TOKEN_KEY => 'token',
            ErrorLoggingTokenService::EXPIRES_AT_KEY => self::NOW + 3601,
        ]);

        self::assertFalse($service->needsRefresh());
        self::assertFalse($service->refreshIfNeeded('api-key'));
    }

    /**
     * @throws JsonException
     */
    public function testMissingTokenIsClaimedAndPersisted(): void
    {
        $this->apiClient->expects($this->once())->method('claimErrorLoggingToken')
            ->willReturn($this->createTokenResponse('new-token', '2027-01-15T12:00:00+00:00'));

        $service = $this->createService([]);

        self::assertTrue($service->refreshIfNeeded('api-key'));
        self::assertSame('new-token', $service->getAccessToken());
        self::assertSame(strtotime('2027-01-15T12:00:00+00:00'), $service->getExpiresAt());
        self::assertSame(
            [
                ErrorLoggingTokenService::TOKEN_KEY => 'new-token',
                ErrorLoggingTokenService::EXPIRES_AT_KEY => strtotime('2027-01-15T12:00:00+00:00'),
            ],
            $this->savedOptions
        );
    }

    /**
     * @throws JsonException
     */
    public function testTokenExpiringWithinTheWindowIsRefreshed(): void
    {
        $this->apiClient->expects($this->once())->method('claimErrorLoggingToken')
            ->willReturn($this->createTokenResponse('fresh', '2027-01-15T12:00:00+00:00'));

        $service = $this->createService([
            ErrorLoggingTokenService::TOKEN_KEY => 'stale',
            ErrorLoggingTokenService::EXPIRES_AT_KEY => self::NOW + 3600,
        ]);

        self::assertTrue($service->refreshIfNeeded('api-key'));
        self::assertSame('fresh', $service->getAccessToken());
    }

    public function testClientFactoryIsNotCalledWhileTheTokenIsValid(): void
    {
        $factoryCalls = 0;
        $service = new ErrorLoggingTokenService(
            $this->createConfigurationManager([
                ErrorLoggingTokenService::TOKEN_KEY => 'token',
                ErrorLoggingTokenService::EXPIRES_AT_KEY => self::NOW + 7200,
            ]),
            function () use (&$factoryCalls): ClientInterface {
                $factoryCalls++;

                return $this->apiClient;
            },
            null,
            $this->createClock()
        );

        $service->refreshIfNeeded('api-key');

        self::assertSame(0, $factoryCalls);
    }

    public function testFailedClaimStartsACooldown(): void
    {
        $this->apiClient->expects($this->once())->method('claimErrorLoggingToken')
            ->willThrowException(new ConnectionTimeout('Timeout.'));

        $cache = new ScopedCache(new ArrayCachePool());
        $service = $this->createService([], $cache);

        self::assertFalse($service->refreshIfNeeded('api-key'));
        self::assertFalse($service->refreshIfNeeded('api-key'), 'The second call is inside the cooldown.');
        self::assertSame([], $this->savedOptions);
    }

    public function testWithoutCacheAFailedClaimIsRetried(): void
    {
        $this->apiClient->expects($this->exactly(2))->method('claimErrorLoggingToken')
            ->willThrowException(new ConnectionTimeout('Timeout.'));

        $service = $this->createService([]);

        $service->refreshIfNeeded('api-key');
        $service->refreshIfNeeded('api-key');
    }

    /**
     * @throws JsonException
     */
    public function testUnparsableExpiryIsTreatedAsAFailure(): void
    {
        $this->apiClient->method('claimErrorLoggingToken')->willReturn($this->createTokenResponse('t', 'not a date'));

        $cache = new ScopedCache(new ArrayCachePool());

        self::assertFalse($this->createService([], $cache)->refreshIfNeeded('api-key'));
        self::assertSame([], $this->savedOptions);
        self::assertTrue($cache->get('error_logging_token_claim_cooldown'));
    }

    /**
     * @param array<string, mixed> $storedOptions
     */
    private function createService(array $storedOptions, ?ScopedCache $cache = null): ErrorLoggingTokenService
    {
        return new ErrorLoggingTokenService(
            $this->createConfigurationManager($storedOptions),
            $this->apiClient,
            $cache,
            $this->createClock()
        );
    }

    /**
     * @param array<string, mixed> $storedOptions
     */
    private function createConfigurationManager(array $storedOptions): ConfigurationManager
    {
        $storageAdapter = $this->createMock(StorageAdapterInterface::class);
        $storageAdapter->method('load')->willReturn($storedOptions);
        $storageAdapter->method('save')->willReturnCallback(function (array $options): void {
            $this->savedOptions = array_merge($this->savedOptions, $options);
        });

        return ConfigurationManager::getInstance(
            [
                ErrorLoggingTokenService::TOKEN_KEY => ConfigurationManager::OPT_VALUE_TYPE_STRING,
                ErrorLoggingTokenService::EXPIRES_AT_KEY => ConfigurationManager::OPT_VALUE_TYPE_INT,
            ],
            [],
            0,
            $storageAdapter,
            new Json()
        );
    }

    private function createClock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(self::NOW);

        return $clock;
    }

    /**
     * @throws JsonException
     */
    private function createTokenResponse(string $accessToken, string $expiresAt): ClaimErrorLoggingTokenResponse
    {
        return new ClaimErrorLoggingTokenResponse(
            new ClaimErrorLoggingTokenRequest(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(
                ['access_token' => $accessToken, 'expires_at' => $expiresAt],
                JSON_THROW_ON_ERROR
            )),
            new Json()
        );
    }

    /**
     * @throws JsonException
     */
    public function testShortLivedTokenStartsACooldownInsteadOfBeingClaimedOnEveryRequest(): void
    {
        $this->apiClient->expects($this->once())->method('claimErrorLoggingToken')
            ->willReturn($this->createTokenResponse('short', gmdate('c', self::NOW + 1800)));

        $cache = new ScopedCache(new ArrayCachePool());
        $service = $this->createService([], $cache);

        self::assertTrue($service->refreshIfNeeded('api-key'));
        self::assertTrue($service->needsRefresh(), 'The new token is still inside the refresh window.');
        self::assertFalse($service->refreshIfNeeded('api-key'), 'The second call is inside the cooldown.');
    }

    /**
     * @throws JsonException
     */
    public function testHealthyTokenStartsNoCooldown(): void
    {
        $this->apiClient->expects($this->once())->method('claimErrorLoggingToken')
            ->willReturn($this->createTokenResponse('long', gmdate('c', self::NOW + 86400)));

        $cache = new ScopedCache(new ArrayCachePool());

        self::assertTrue($this->createService([], $cache)->refreshIfNeeded('api-key'));
        self::assertNull($cache->get('error_logging_token_claim_cooldown'));
    }
}
