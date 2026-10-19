<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Update
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Update;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Api\ClientInterface;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Request\GetLatestPluginRelease as GetLatestPluginReleaseRequest;
use Comfino\Api\Response\GetLatestPluginRelease as GetLatestPluginReleaseResponse;
use Comfino\Api\Serializer\Json;
use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Clock\ClockInterface;
use Comfino\Backend\Queue\JitterInterface;
use Comfino\Backend\Update\PluginUpdateChecker;
use JsonException;
use LogicException;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class PluginUpdateCheckerTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private ClientInterface&MockObject $apiClient;
    private ArrayCachePool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiClient = $this->createMock(ClientInterface::class);
        $this->pool = new ArrayCachePool();
    }

    /**
     * @throws JsonException
     */
    public function testNewerReleaseIsReported(): void
    {
        $this->apiClient->expects($this->once())->method('getLatestPluginRelease')->with('magento')->willReturn($this->createRelease('4.1.0'));

        $result = $this->createChecker('4.0.1')->check();

        self::assertTrue($result['update_available']);
        self::assertSame('4.0.1', $result['current_version']);
        self::assertSame('4.1.0', $result['github_version']);
        self::assertSame('https://example.com/download.zip', $result['download_url']);
        self::assertSame('https://example.com/release', $result['release_notes_url']);
        self::assertSame('<p>Notes</p>', $result['description_html']);
        self::assertSame(self::NOW, $result['checked_at']);
    }

    /**
     * @throws JsonException
     */
    public function testSameReleaseIsNotAnUpdate(): void
    {
        $this->apiClient->method('getLatestPluginRelease')->willReturn($this->createRelease('v4.0.1'));

        self::assertFalse($this->createChecker('4.0.1')->check()['update_available']);
    }

    public function testApiFailureIsReportedAsErrorAndCached(): void
    {
        $this->apiClient->expects($this->once())->method('getLatestPluginRelease')->willThrowException(new ConnectionTimeout('Timeout.'));

        $checker = $this->createChecker('4.0.1');
        $result = $checker->check();

        self::assertFalse($result['update_available']);
        self::assertStringContainsString('Timeout.', $result['error'] ?? '');
        self::assertSame($result, $checker->check(), 'The failure is cached like a result, so the API is not re-probed.');
    }

    /**
     * @throws JsonException
     */
    public function testSecondCallIsServedFromCache(): void
    {
        $this->apiClient->expects($this->once())->method('getLatestPluginRelease')->willReturn($this->createRelease('4.1.0'));

        $checker = $this->createChecker('4.0.1');
        $checker->check();

        self::assertTrue($checker->check()['update_available']);
    }

    /**
     * @throws JsonException
     */
    public function testCachedResultIsReevaluatedAgainstTheInstalledVersion(): void
    {
        $this->apiClient->expects($this->once())->method('getLatestPluginRelease')->willReturn($this->createRelease('4.1.0'));

        self::assertTrue($this->createChecker('4.0.1')->check()['update_available']);

        // The shop upgraded to 4.1.0 while the result was still cached.
        $afterUpgrade = $this->createChecker('4.1.0')->check();

        self::assertFalse($afterUpgrade['update_available']);
        self::assertSame('4.1.0', $afterUpgrade['current_version']);
    }

    public function testConcurrentCheckWhileLockedDoesNotCallTheApi(): void
    {
        $this->apiClient->expects($this->never())->method('getLatestPluginRelease');

        $cache = new ScopedCache($this->pool);
        $cache->set('plugin_update_check_lock', true, 300);

        self::assertSame(
            ['update_available' => false, 'current_version' => '4.0.1'],
            $this->createChecker('4.0.1', $cache)->check()
        );
    }

    /**
     * @throws JsonException
     */
    public function testForceCheckBypassesTheCacheAndTheLock(): void
    {
        $this->apiClient->expects($this->exactly(2))->method('getLatestPluginRelease')->willReturn($this->createRelease('4.1.0'));

        $checker = $this->createChecker('4.0.1');
        $checker->check();

        self::assertTrue($checker->forceCheck()['update_available']);
    }

    /**
     * @throws JsonException
     */
    public function testResultTtlIsSpreadBetweenTheBounds(): void
    {
        $this->apiClient->method('getLatestPluginRelease')->willReturn($this->createRelease('4.1.0'));

        $jitter = $this->createMock(JitterInterface::class);
        $jitter->expects($this->once())->method('random')->with(100800 - 72000)->willReturn(1234);

        $ttls = [];
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnCallback(
            static function (int $ttl) use (&$ttls, $item): CacheItemInterface {
                $ttls[] = $ttl;

                return $item;
            }
        );

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        $this->createChecker('4.0.1', new ScopedCache($pool), $jitter)->check();

        self::assertSame([300, 72000 + 1234], $ttls, 'Lock TTL first, then the jittered result TTL.');
    }

    /**
     * @throws JsonException
     */
    public function testWithoutCacheEveryCallQueriesTheApi(): void
    {
        $this->apiClient->expects($this->exactly(2))->method('getLatestPluginRelease')->willReturn($this->createRelease('4.1.0'));

        $checker = new PluginUpdateChecker($this->apiClient, 'magento', '4.0.1');
        $checker->check();
        $checker->check();
    }

    /**
     * @throws JsonException
     */
    public function testClientFactoryIsNotCalledOnACacheHit(): void
    {
        $this->apiClient->method('getLatestPluginRelease')->willReturn($this->createRelease('4.1.0'));
        $this->createChecker('4.0.1')->check();

        $factoryCalls = 0;
        $checker = new PluginUpdateChecker(
            static function () use (&$factoryCalls): ClientInterface {
                $factoryCalls++;

                throw new LogicException('Must not be called.');
            },
            'magento',
            '4.0.1',
            new ScopedCache($this->pool)
        );

        self::assertTrue($checker->check()['update_available']);
        self::assertSame(0, $factoryCalls);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function versionProvider(): array
    {
        return [
            'newer minor' => ['4.1.0', '4.0.1', true],
            'equal' => ['4.0.1', '4.0.1', false],
            'older' => ['4.0.0', '4.0.1', false],
            'v prefix on available' => ['v4.0.2', '4.0.1', true],
            'V prefix on current' => [' 4.0.1 ', 'V4.0.1', false],
            'stable after beta' => ['4.1.0', '4.1.0-beta1', true],
        ];
    }

    #[DataProvider('versionProvider')]
    public function testIsNewerVersion(string $available, string $current, bool $expected): void
    {
        self::assertSame($expected, PluginUpdateChecker::isNewerVersion($available, $current));
    }

    private function createChecker(
        string $currentVersion,
        ?ScopedCache $cache = null,
        ?JitterInterface $jitter = null
    ): PluginUpdateChecker {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(self::NOW);

        if ($jitter === null) {
            $jitter = $this->createMock(JitterInterface::class);
            $jitter->method('random')->willReturn(0);
        }

        return new PluginUpdateChecker(
            $this->apiClient,
            'magento',
            $currentVersion,
            $cache ?? new ScopedCache($this->pool),
            $clock,
            $jitter
        );
    }

    /**
     * @throws JsonException
     */
    private function createRelease(string $version): GetLatestPluginReleaseResponse
    {
        return new GetLatestPluginReleaseResponse(
            new GetLatestPluginReleaseRequest('magento'),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'platform' => 'magento',
                'version' => $version,
                'download_url' => 'https://example.com/download.zip',
                'release_url' => 'https://example.com/release',
                'description_html' => '<p>Notes</p>',
            ], JSON_THROW_ON_ERROR)),
            new Json()
        );
    }
}
