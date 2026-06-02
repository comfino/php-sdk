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

use Comfino\Api\Dto\Payment\AllowedProductConfig;
use Comfino\Api\SerializerInterface;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Configuration\StorageAdapterInterface;
use Comfino\Backend\Settings\LanguageProviderInterface;
use Comfino\Backend\Settings\SettingsManager;
use Comfino\Enum\LoanType;
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

    private function createSettingsManager(?ConfigurationManager $configManager = null): SettingsManager
    {
        return SettingsManager::getInstance(
            $this->languageProvider,
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            null,
            $configManager,
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
        $this->assertInstanceOf(AllowedProductConfig::class, $result[0]);
        $this->assertSame(LoanType::PAY_LATER, $result[0]->type);
        $this->assertSame(6, $result[0]->maxTerm);
        $this->assertSame(1, $result[0]->minTerm);
        $this->assertInstanceOf(AllowedProductConfig::class, $result[1]);
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
}
