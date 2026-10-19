<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Settings
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Settings;

use Cache\Adapter\PHPArray\ArrayCachePool;
use Comfino\Api\Serializer\Json;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\Backend\Payment\Filter\FilterByCartValueLowerLimit;
use Comfino\Backend\Payment\Filter\FilterByExcludedCategory;
use Comfino\Backend\Payment\Filter\FilterByProductType;
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\Backend\Settings\SettingsManager;
use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Shop\Product\CategoryTree;
use Comfino\Shop\Product\CategoryTreeProviderInterface;
use Comfino\Tests\Unit\Support\ProductFilteringFixtures;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class SettingsManagerConfiguredFiltersTest extends TestCase
{
    use ProductFilteringFixtures;

    private const PRODUCT_TYPES = [
        'INSTALLMENTS_ZERO_PERCENT' => 'Zero percent installments',
        'PAY_LATER' => 'Pay later',
        'COMPANY_BNPL' => 'Company BNPL',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        SettingsManager::reset();
        ConfigurationManager::reset();
        ProductTypeFilterManager::reset();
        CacheManager::reset();
        CacheManager::init(new ArrayCachePool());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SettingsManager::reset();
        ConfigurationManager::reset();
        ProductTypeFilterManager::reset();
        CacheManager::reset();
    }

    public function testNoConfiguredFiltersMeansNoRestriction(): void
    {
        $manager = $this->createManager([]);

        self::assertSame([], $manager->buildConfiguredFilters('paywall'));
        self::assertNull($manager->getAllowedProductTypesFiltered(self::cart(self::product('1')), 'paywall'));
        self::assertNull($manager->getAllowedProductTypesFiltered(self::cart(self::product('1')), 'paywall', true));
    }

    public function testMinimalCartAmountIsConvertedToCentsWithoutFloatTruncation(): void
    {
        // 12.34 * 100 is 1233.9999... in floating point; truncating it would give 1233.
        self::assertSame(1234, $this->createManager(['COMFINO_MINIMAL_CART_AMOUNT' => 12.34])->getMinimalCartAmount());

        SettingsManager::reset();
        ConfigurationManager::reset();

        self::assertSame(0, $this->createManager(['COMFINO_MINIMAL_CART_AMOUNT' => ''])->getMinimalCartAmount());
    }

    public function testMinimalCartAmountFiltersOutEveryTypeBelowTheLimit(): void
    {
        $manager = $this->createManager(['COMFINO_MINIMAL_CART_AMOUNT' => 150.0]);

        $filters = $manager->buildConfiguredFilters('paywall');

        self::assertCount(1, $filters);
        self::assertInstanceOf(FilterByCartValueLowerLimit::class, $filters[0]);
        self::assertSame(
            ['cartValueLimitsByProductType' => array_fill_keys(array_keys(self::PRODUCT_TYPES), 15000)],
            $filters[0]->getAsArray()
        );

        // One product = 100.00 PLN, below the 150.00 limit.
        self::assertSame([], $manager->getAllowedProductTypesFiltered(self::cart(self::product('1')), 'paywall'));
        // Two products = 200.00 PLN: nothing is filtered out, so the result means "no restriction".
        self::assertNull(
            $manager->getAllowedProductTypesFiltered(self::cart(self::product('1'), self::product('2')), 'paywall')
        );
        self::assertCount(
            3,
            (array) $manager->getAllowedProductTypesFiltered(
                self::cart(self::product('1'), self::product('2')),
                'paywall',
                true
            )
        );
    }

    public function testExcludedCategoryFiltersOnlyTheAffectedType(): void
    {
        $manager = $this->createManager(['COMFINO_PRODUCT_CATEGORY_FILTERS' => ['PAY_LATER' => [10]]]);
        $manager->setCategoryTreeProvider($this->createTreeProvider());

        $filters = $manager->buildConfiguredFilters('paywall');

        self::assertCount(1, $filters);
        self::assertInstanceOf(FilterByExcludedCategory::class, $filters[0]);

        // A gaming laptop (100) is a descendant of the excluded Laptops category (10).
        self::assertSame(
            ['INSTALLMENTS_ZERO_PERCENT', 'COMPANY_BNPL'],
            self::values($manager->getAllowedProductTypesFiltered(self::cart(self::product('1', null, [100])), 'paywall'))
        );
        self::assertNull($manager->getAllowedProductTypesFiltered(self::cart(self::product('2', null, [2])), 'paywall'));
    }

    public function testCategoryFilterValuesAreNormalizedToIntegerIds(): void
    {
        $manager = $this->createManager([
            'COMFINO_PRODUCT_CATEGORY_FILTERS' => ['PAY_LATER' => '10, 11', 'BLIK' => ['2'], 'COMPANY_BNPL' => []],
        ]);
        $manager->setCategoryTreeProvider($this->createTreeProvider());

        self::assertSame(
            ['excludedCategoryIdsByProductType' => ['PAY_LATER' => [10, 11], 'BLIK' => [2]]],
            $manager->buildConfiguredFilters('paywall')[0]->getAsArray()
        );
    }

    public function testCategoryTreeIsRequestedOnlyWhenACategoryFilterIsConfigured(): void
    {
        $provider = $this->createMock(CategoryTreeProviderInterface::class);
        $provider->expects($this->never())->method('getCategoryTree');

        $manager = $this->createManager(['COMFINO_PRODUCT_CATEGORY_FILTERS' => ['PAY_LATER' => []]]);
        $manager->setCategoryTreeProvider($provider);

        self::assertSame([], $manager->buildConfiguredFilters('paywall'));
    }

    public function testConfiguredCategoryFilterWithoutTreeProviderIsAProgrammingError(): void
    {
        $this->expectException(LogicException::class);

        $this->createManager(['COMFINO_PRODUCT_CATEGORY_FILTERS' => ['PAY_LATER' => [10]]])->buildConfiguredFilters('paywall');
    }

    public function testFiltersRegisteredOnTheScopeManagerAreKeptAndTheSharedManagerIsNotModified(): void
    {
        ProductTypeFilterManager::getInstance()->addFilter(new FilterByProductType([LoanType::PAY_LATER]));

        $manager = $this->createManager(['COMFINO_MINIMAL_CART_AMOUNT' => 50.0]);

        self::assertCount(2, $manager->getConfiguredFilterManager('paywall')->getFilters());
        self::assertCount(1, ProductTypeFilterManager::getInstance()->getFilters());
        self::assertSame(
            ['PAY_LATER'],
            self::values($manager->getAllowedProductTypesFiltered(self::cart(self::product('1')), 'paywall'))
        );
    }

    /** The option is JSON-typed, so a JSON string literal is what a legacy comma-separated value loads as. */
    public function testCategoryFiltersPersistedAsStringAreSplit(): void
    {
        $manager = $this->createManager(['COMFINO_PRODUCT_CATEGORY_FILTERS' => '"10, 11"']);

        self::assertSame(['10', '11'], $manager->getProductCategoryFilters());
    }

    public function testProductCategoryFiltersActive(): void
    {
        self::assertFalse(SettingsManager::productCategoryFiltersActive([]));
        self::assertFalse(SettingsManager::productCategoryFiltersActive(['PAY_LATER' => [], 'BLIK' => '']));
        self::assertTrue(SettingsManager::productCategoryFiltersActive(['PAY_LATER' => [], 'BLIK' => [5]]));
    }

    public function testCatFilterAvailProdTypesIntersectsWithConfiguredTypes(): void
    {
        $manager = $this->createManager(['COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' => 'PAY_LATER,COMPANY_BNPL,UNKNOWN']);

        self::assertSame(
            ['PAY_LATER' => 'Pay later', 'COMPANY_BNPL' => 'Company BNPL'],
            $manager->getCatFilterAvailProdTypes()
        );
    }

    public function testCatFilterAvailProdTypesFallsBackToAllTypesOnEmptyIntersection(): void
    {
        $manager = $this->createManager(['COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' => ['UNKNOWN']]);

        self::assertSame(self::PRODUCT_TYPES, $manager->getCatFilterAvailProdTypes());
    }

    public function testResolveAllowedProductTypesIsNeverNull(): void
    {
        // No filter configured: the full available list, not null.
        self::assertSame(
            array_keys(self::PRODUCT_TYPES),
            self::values($this->createManager([])->resolveAllowedProductTypes('paywall', self::cart(self::product('1'))))
        );

        SettingsManager::reset();
        ConfigurationManager::reset();

        // Every type filtered out: [].
        self::assertSame(
            [],
            $this->createManager(['COMFINO_MINIMAL_CART_AMOUNT' => 500.0])->resolveAllowedProductTypes('paywall', self::cart(self::product('1')))
        );
    }

    public function testResolveAllowedProductTypesIsEmptyWhenTheLookupFails(): void
    {
        // No cached product types and no API key, so the lookup yields nothing.
        self::assertSame([], SettingsManager::getInstance(
            $this->createMock(LanguageProviderInterface::class),
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
            null,
            null,
            '',
            false,
            null,
            'no_key'
        )->resolveAllowedProductTypes('paywall', self::cart(self::product('1'))));
    }

    /**
     * @param array<string, mixed> $configValues
     */
    private function createManager(array $configValues): SettingsManager
    {
        CacheManager::forScope('')->set('product_types.paywall.pl', self::PRODUCT_TYPES);

        $storageAdapter = $this->createMock(StorageAdapterInterface::class);
        $storageAdapter->method('load')->willReturn($configValues);

        $configurationManager = ConfigurationManager::getInstance(
            [
                'COMFINO_MINIMAL_CART_AMOUNT' => ConfigurationManager::OPT_VALUE_TYPE_FLOAT,
                'COMFINO_PRODUCT_CATEGORY_FILTERS' => ConfigurationManager::OPT_VALUE_TYPE_JSON,
                'COMFINO_CAT_FILTER_AVAIL_PROD_TYPES' => ConfigurationManager::OPT_VALUE_TYPE_STRING_ARRAY,
            ],
            [],
            0,
            $storageAdapter,
            new Json()
        );

        $languageProvider = $this->createMock(LanguageProviderInterface::class);
        $languageProvider->method('getLanguage')->willReturn('pl');

        return SettingsManager::getInstance(
            $languageProvider,
            $this->createMock(ClientInterface::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(StreamFactoryInterface::class),
            null,
            $configurationManager,
            'test-api-key',
            false
        );
    }

    private function createTreeProvider(): CategoryTreeProviderInterface
    {
        return new class (self::categoryTree()) implements CategoryTreeProviderInterface {
            public function __construct(private readonly CategoryTree $tree)
            {
            }

            public function getCategoryTree(): CategoryTree
            {
                return $this->tree;
            }
        };
    }

    /**
     * @param LoanTypeInterface[]|null $productTypes
     *
     * @return string[]
     */
    private static function values(?array $productTypes): array
    {
        return array_map(static fn (LoanTypeInterface $type): string => $type->getValue(), $productTypes ?? []);
    }
}
