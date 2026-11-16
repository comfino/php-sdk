<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Settings;

use Cache\Adapter\Common\HasExpirationTimestampInterface;
use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\Backend\Settings\SettingsManager;
use Comfino\Enum\CacheItemType;
use Comfino\Tests\Unit\Support\FailingHttpClient;
use Comfino\Tests\Unit\Support\ScriptedFlagsClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Http\Client\ClientInterface;

final class RemoteFlagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SettingsManager::reset();
        ConfigurationManager::reset();
        CacheManager::reset();
        CacheManager::init(new ArrayCachePool());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SettingsManager::reset();
        ConfigurationManager::reset();
        CacheManager::reset();
    }

    public function testFlagsAreFetchedOnceAndKeptVerbatimIncludingPromo(): void
    {
        $promo = ['amount' => 5000, 'minAmount' => 10000, 'allowedTypes' => ['INSTALLMENTS_ZERO_PERCENT']];
        $body = ['flags' => ['PAYMENT_ITEM_PROMOTION' => [], 'PROMO' => $promo]];
        $http = new ScriptedFlagsClient([new Response(200, ['Content-Type' => 'application/json'], json_encode($body))]);

        $manager = $this->manager($http);

        $this->assertSame($body['flags'], $manager->getRemoteFlagAttributes());
        $this->assertSame($body['flags'], $manager->getCheckoutFlagAttributes());
        $this->assertSame(['PAYMENT_ITEM_PROMOTION'], $manager->getRemoteFlagNames());

        // The next request reads the cache and does not ask the API.
        SettingsManager::reset();

        $this->assertSame($body['flags'], $this->manager($http)->getRemoteFlagAttributes());
        $this->assertSame(1, $http->requestCount);
    }

    public function testFailedLookupReturnsEmptyAndIsNotRetried(): void
    {
        $http = new FailingHttpClient();
        $manager = $this->manager($http);

        $this->assertSame([], $manager->getRemoteFlagAttributes());
        $this->assertNull($manager->getCheckoutFlagAttributes());

        $attempts = $http->requestCount;

        $this->assertGreaterThan(0, $attempts);

        SettingsManager::reset();

        $this->assertSame([], $this->manager($http)->getRemoteFlagAttributes());
        $this->assertSame($attempts, $http->requestCount);
    }

    public function testNoApiKeyMeansNoRequest(): void
    {
        $http = new ScriptedFlagsClient([]);

        $this->assertSame([], $this->manager($http, '')->getRemoteFlagAttributes());
        $this->assertSame(0, $http->requestCount);
    }

    public function testAClearedCacheEntryIsFetchedAgain(): void
    {
        $json = ['Content-Type' => 'application/json'];
        $first = ['flags' => ['A_FLAG' => []]];
        $second = ['flags' => ['A_FLAG' => [], 'B_FLAG' => []]];
        $http = new ScriptedFlagsClient([
            new Response(200, $json, json_encode($first)),
            new Response(200, $json, json_encode($second)),
        ]);

        $this->assertSame($first['flags'], $this->manager($http)->getRemoteFlagAttributes());

        // What a signed `remote_flags` invalidation does to the stored entry.
        CacheManager::forScope('')->delete('remote_flags');
        SettingsManager::reset();

        $this->assertSame($second['flags'], $this->manager($http)->getRemoteFlagAttributes());
        $this->assertSame(2, $http->requestCount);
    }

    public function testSanitizerKeepsOnlyWellNamedEntriesAndPassesValuesThrough(): void
    {
        $flags = [
            'GOOD_FLAG' => ['nested' => ['Mixed_Case' => 1]],
            'scalar' => true,
            'SCALAR_STRING' => 'x',
            'lower_case' => [],
            'BAD-NAME' => [],
            str_repeat('A', 65) => [],
            'BROKEN' => NAN,
            0 => 'numeric key',
        ];

        $this->assertSame(
            ['GOOD_FLAG' => ['nested' => ['Mixed_Case' => 1]], 'SCALAR_STRING' => 'x'],
            SettingsManager::sanitizeRemoteFlagAttributes($flags)
        );
    }

    public function testCacheLifetimeConstants(): void
    {
        $this->assertSame('remote_flags', SettingsManager::REMOTE_FLAGS_CACHE_TAG);
        $this->assertSame(CacheItemType::REMOTE_FLAGS->value, SettingsManager::REMOTE_FLAGS_CACHE_TAG);
        $this->assertSame(86400, SettingsManager::REMOTE_FLAGS_MAX_CACHE_TTL);
        $this->assertSame(300, SettingsManager::REMOTE_FLAGS_FAILED_LOOKUP_CACHE_TTL);
    }

    private function manager(ClientInterface $http, string $apiKey = 'test-api-key'): SettingsManager
    {
        $psr17 = new Psr17Factory();
        $language = $this->createMock(LanguageProviderInterface::class);
        $language->method('getLanguage')->willReturn('pl');

        return SettingsManager::getInstance($language, $http, $psr17, $psr17, null, null, $apiKey, false);
    }

    /**
     * @return array<string, array{array<string, string>, bool}> Headers, whether the entry must expire
     */
    public static function cacheTtlHeaderProvider(): array
    {
        return [
            'header missing' => [[], true],
            'header zero' => [['Comfino-Cache-TTL' => '0'], true],
            'header negative' => [['Comfino-Cache-TTL' => '-5'], true],
            'header positive' => [['Comfino-Cache-TTL' => '120'], true],
        ];
    }

    /**
     * @dataProvider cacheTtlHeaderProvider
     *
     * @param array<string, string> $headers
     */
    public function testListsTheApiNamesNoLifetimeForAreStillCachedWithAnExpiry(array $headers, bool $mustExpire): void
    {
        $pool = new class extends ArrayCachePool {
            /** @var array<string, int|null> Expiry timestamp of each saved item, by key */
            public array $savedExpiries = [];

            public function save(CacheItemInterface $item): bool
            {
                $this->savedExpiries[$item->getKey()] = $item instanceof HasExpirationTimestampInterface ? $item->getExpirationTimestamp() : null;

                return parent::save($item);
            }
        };

        CacheManager::init($pool);

        $http = new ScriptedFlagsClient([
            new Response(200, ['Content-Type' => 'application/json'] + $headers, json_encode(['PAY_LATER' => ['twisto']])),
        ]);

        $this->assertSame(['PAY_LATER' => ['twisto']], $this->manager($http)->getCreditors());

        $expiresAt = $pool->savedExpiries['creditors'] ?? null;

        $this->assertNotNull($expiresAt, 'An entry without an API-named lifetime must still expire.');

        $ttl = isset($headers['Comfino-Cache-TTL']) && (int) $headers['Comfino-Cache-TTL'] > 0
            ? (int) $headers['Comfino-Cache-TTL']
            : SettingsManager::FALLBACK_CACHE_TTL;

        $this->assertEqualsWithDelta(time() + $ttl, $expiresAt, 5);
    }

    public function testFallbackCacheLifetimeIsOneHour(): void
    {
        $this->assertSame(3600, SettingsManager::FALLBACK_CACHE_TTL);
    }
}
