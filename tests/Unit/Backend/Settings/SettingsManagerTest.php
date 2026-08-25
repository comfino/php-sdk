<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Settings
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Settings;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Api\SerializerInterface;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\Backend\Settings\SettingsManager;
use Comfino\Enum\LoanType;
use Comfino\Tests\Unit\Support\FailingHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class SettingsManagerTest extends TestCase
{
    private LanguageProviderInterface&MockObject $languageProvider;
    private ClientInterface&MockObject $httpClient;
    private RequestFactoryInterface&MockObject $requestFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private StorageAdapterInterface&MockObject $storageAdapter;
    private SerializerInterface&MockObject $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->languageProvider = $this->createMock(LanguageProviderInterface::class);
        $this->languageProvider->method('getLanguage')->willReturn('pl');

        $this->httpClient = $this->createMock(ClientInterface::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);
        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->storageAdapter = $this->createMock(StorageAdapterInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);

        SettingsManager::reset();
        ConfigurationManager::reset();
        CacheManager::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SettingsManager::reset();
        ConfigurationManager::reset();
        CacheManager::reset();
    }

    private function createSettingsManager(
        ?ConfigurationManager $configManager = null,
        string $apiKey = 'test-api-key',
        string $scope = ''
    ): SettingsManager {
        return SettingsManager::getInstance(
            $this->languageProvider,
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            null,
            $configManager,
            $apiKey,
            false,
            null,
            $scope
        );
    }

    /**
     * The first tenant to ask for a manager used to fix the API key, the sandbox flag and the API base URL for every
     * tenant after it - and, because the instance memoizes every lookup it makes, served the first tenant's product
     * types, widget types and creditor lists to everyone.
     */
    public function testEachScopeGetsItsOwnInstance(): void
    {
        $shopA = $this->createSettingsManager(null, 'key_for_shop_a', 'shop_a');
        $shopB = $this->createSettingsManager(null, 'key_for_shop_b', 'shop_b');

        $this->assertNotSame($shopA, $shopB);
        $this->assertSame('shop_a', $shopA->getScope());
        $this->assertSame($shopA, $this->createSettingsManager(null, 'ignored-because-cached', 'shop_a'));
    }

    /**
     * The scope is also the cache partition, so two tenants sharing a cache backend cannot read each other's entries.
     */
    public function testCachedLookupsArePartitionedByScope(): void
    {
        CacheManager::init(new ArrayCachePool());
        CacheManager::forScope('shop_a')->set('product_types.paywall.pl', ['A' => 'Shop A product']);

        $this->assertSame(
            ['A' => 'Shop A product'],
            $this->createSettingsManager(null, 'key_for_shop_a', 'shop_a')->getProductTypes()
        );
        $this->assertNull(
            $this->createSettingsManager(null, 'key_for_shop_b', 'shop_b')->getProductTypes(),
            'Shop B has no cached entry of its own, and must not be served shop A\'s.'
        );
    }

    public function testResetDropsOnlyTheNamedScope(): void
    {
        $shopA = $this->createSettingsManager(null, 'key_for_shop_a', 'shop_a');
        $shopB = $this->createSettingsManager(null, 'key_for_shop_b', 'shop_b');

        SettingsManager::reset('shop_a');

        $this->assertNotSame($shopA, $this->createSettingsManager(null, 'key_for_shop_a', 'shop_a'));
        $this->assertSame($shopB, $this->createSettingsManager(null, 'key_for_shop_b', 'shop_b'));
    }

    /**
     * Builds a SettingsManager over real PSR-17 factories, so that requests actually reach the given HTTP client.
     * The factory mocks used elsewhere cannot be used here: their withHeader() chain yields a MessageInterface stub
     * that fails the PSR-18 sendRequest() signature before any request is sent.
     */
    private function createSettingsManagerWithHttpClient(ClientInterface $httpClient): SettingsManager
    {
        $psr17Factory = new Psr17Factory();

        return SettingsManager::getInstance(
            $this->languageProvider,
            $httpClient,
            $psr17Factory,
            $psr17Factory,
            null,
            null,
            'test-api-key',
            false
        );
    }

    /** @param array<string, mixed> $configValues */
    private function createConfigurationManager(array $configValues = []): ConfigurationManager
    {
        $this->storageAdapter->method('load')->willReturn($configValues);

        return ConfigurationManager::getInstance(
            array_combine(
                array_keys($configValues),
                array_fill(0, count($configValues), ConfigurationManager::OPT_VALUE_TYPE_JSON)
            ),
            array_keys($configValues),
            0,
            $this->storageAdapter,
            $this->serializer
        );
    }

    public function testGetAllowedProductsConfigReturnsNullWhenNoConfigManager(): void
    {
        $manager = $this->createSettingsManager(null);

        $this->assertNull($manager->getAllowedProductsConfig());
    }

    public function testGetAllowedProductsConfigReturnsNullWhenConfigIsEmpty(): void
    {
        $configManager = $this->createConfigurationManager([
            'COMFINO_ALLOWED_PRODUCTS_CONFIG' => [],
        ]);

        $manager = $this->createSettingsManager($configManager);

        $this->assertNull($manager->getAllowedProductsConfig());
    }

    public function testGetAllowedProductsConfigReturnsNullWhenConfigIsNull(): void
    {
        $configManager = $this->createConfigurationManager([
            'COMFINO_ALLOWED_PRODUCTS_CONFIG' => null,
        ]);

        $manager = $this->createSettingsManager($configManager);

        $this->assertNull($manager->getAllowedProductsConfig());
    }

    public function testGetAllowedProductsConfigReturnsDtosForPopulatedConfig(): void
    {
        $configManager = $this->createConfigurationManager([
            'COMFINO_ALLOWED_PRODUCTS_CONFIG' => [
                ['type' => 'PAY_LATER', 'maxTerm' => 6, 'minTerm' => 1],
                ['type' => 'INSTALLMENTS_ZERO_PERCENT', 'terms' => [3, 6]],
            ],
        ]);

        $manager = $this->createSettingsManager($configManager);
        $result = $manager->getAllowedProductsConfig();

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertSame(LoanType::PAY_LATER, $result[0]->type);
        $this->assertSame(6, $result[0]->maxTerm);
        $this->assertSame(1, $result[0]->minTerm);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $result[1]->type);
        $this->assertSame([3, 6], $result[1]->terms);
    }

    public function testGetAllowedProductsConfigForFrontendReturnsNullWhenNoConfig(): void
    {
        $manager = $this->createSettingsManager(null);

        $this->assertNull($manager->getAllowedProductsConfigForFrontend());
    }

    public function testGetAllowedProductsConfigForFrontendReturnsPlainArrays(): void
    {
        $configManager = $this->createConfigurationManager([
            'COMFINO_ALLOWED_PRODUCTS_CONFIG' => [
                ['type' => 'PAY_LATER', 'maxTerm' => 6],
            ],
        ]);

        $manager = $this->createSettingsManager($configManager);
        $result = $manager->getAllowedProductsConfigForFrontend();

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame('PAY_LATER', $result[0]['type']);
        $this->assertSame(6, $result[0]['maxTerm']);
    }

    public function testGetAllowedProductsConfigIsCachedInMemory(): void
    {
        $configManager = $this->createConfigurationManager([
            'COMFINO_ALLOWED_PRODUCTS_CONFIG' => [
                ['type' => 'PAY_LATER'],
            ],
        ]);

        $this->storageAdapter->expects($this->once())->method('load');

        $manager = $this->createSettingsManager($configManager);
        $manager->getAllowedProductsConfig();
        $manager->getAllowedProductsConfig();
    }

    public function testFailedProductTypesLookupIsNotRetriedWithinTheSameRequest(): void
    {
        CacheManager::init(new ArrayCachePool());

        $httpClient = new FailingHttpClient();
        $manager = $this->createSettingsManagerWithHttpClient($httpClient);

        $this->assertNull($manager->getProductTypes());

        $attemptsAfterFirstLookup = $httpClient->requestCount;

        $this->assertGreaterThan(0, $attemptsAfterFirstLookup);
        $this->assertNull($manager->getProductTypes());
        $this->assertSame($attemptsAfterFirstLookup, $httpClient->requestCount);
    }

    public function testFailedProductTypesLookupIsNotRetriedByASubsequentRequest(): void
    {
        CacheManager::init(new ArrayCachePool());

        $httpClient = new FailingHttpClient();

        $this->assertNull($this->createSettingsManagerWithHttpClient($httpClient)->getProductTypes());

        $attemptsAfterFirstLookup = $httpClient->requestCount;

        $this->assertGreaterThan(0, $attemptsAfterFirstLookup);

        // A new SettingsManager instance stands in for the next incoming request hitting a warm cache pool.
        SettingsManager::reset();

        $this->assertNull($this->createSettingsManagerWithHttpClient($httpClient)->getProductTypes());
        $this->assertSame($attemptsAfterFirstLookup, $httpClient->requestCount);
    }

    public function testFailedCreditorsLookupIsNotRetriedWithinTheSameRequest(): void
    {
        CacheManager::init(new ArrayCachePool());

        $httpClient = new FailingHttpClient();
        $manager = $this->createSettingsManagerWithHttpClient($httpClient);

        $this->assertNull($manager->getCreditors());

        $attemptsAfterFirstLookup = $httpClient->requestCount;

        $this->assertGreaterThan(0, $attemptsAfterFirstLookup);
        $this->assertNull($manager->getCreditors());
        $this->assertSame($attemptsAfterFirstLookup, $httpClient->requestCount);
    }

    public function testFailedWidgetTypesLookupIsNotRetriedWithinTheSameRequest(): void
    {
        CacheManager::init(new ArrayCachePool());

        $httpClient = new FailingHttpClient();
        $manager = $this->createSettingsManagerWithHttpClient($httpClient);

        $this->assertNull($manager->getWidgetTypes());

        $attemptsAfterFirstLookup = $httpClient->requestCount;

        $this->assertGreaterThan(0, $attemptsAfterFirstLookup);
        $this->assertNull($manager->getWidgetTypes());
        $this->assertSame($attemptsAfterFirstLookup, $httpClient->requestCount);
    }

    public function testFailedLookupsAreNegativeCachedPerLookup(): void
    {
        CacheManager::init(new ArrayCachePool());

        $httpClient = new FailingHttpClient();
        $manager = $this->createSettingsManagerWithHttpClient($httpClient);

        /* Each lookup gets its own negative cache entry, so a failure of one must not suppress the others: two
           distinct lookups have to reach the API, and repeating both of them must not add any further attempt. */
        $this->assertNull($manager->getProductTypes());

        $attemptsAfterProductTypes = $httpClient->requestCount;

        $this->assertNull($manager->getCreditors());

        $attemptsAfterBothLookups = $httpClient->requestCount;

        $this->assertGreaterThan($attemptsAfterProductTypes, $attemptsAfterBothLookups);
        $this->assertNull($manager->getProductTypes());
        $this->assertNull($manager->getCreditors());
        $this->assertSame($attemptsAfterBothLookups, $httpClient->requestCount);
    }
}
