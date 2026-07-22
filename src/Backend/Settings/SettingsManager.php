<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Settings
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Settings;

use Comfino\Api\Client;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Factory\ApiClientFactory;
use Comfino\Backend\Payment\AllowedProductsConfigBuilder;
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Platform\PlatformInfoInterface;
use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Cart;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Consolidated product and widget types manager.
 *
 * Singleton. Fetches product and widget types from the Comfino API, caches them via CacheManager, and applies
 * product-type filters.
 */
final class SettingsManager
{
    private static ?self $instance = null;
    private ?Client $apiClient = null;

    /** @var array<string, array<string, string>|null> Keyed by "listType.language" */
    private array $productTypesCache = [];
    /** @var array<string, string>|null null = not yet fetched */
    private ?array $widgetTypesCache = null;
    /** @var array<string, string[]>|null null = not yet fetched */
    private ?array $creditorsCache = null;
    /** @var \Comfino\Api\Dto\Payment\AllowedProductConfig[]|null */
    private ?array $allowedProductsConfigCache = null;

    private function __construct(
        private readonly LanguageProviderInterface $languageProvider,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?PlatformInfoInterface $platformInfo,
        private readonly ?ConfigurationManager $configurationManager,
        private readonly string $apiKey,
        private readonly bool $sandboxMode,
        private readonly ?string $customApiBaseUrl = null
    ) {
    }

    public static function getInstance(
        LanguageProviderInterface $languageProvider,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?PlatformInfoInterface $platformInfo,
        ?ConfigurationManager $configurationManager,
        string $apiKey,
        bool $sandboxMode,
        ?string $customApiBaseUrl = null
    ): self {
        if (self::$instance === null) {
            self::$instance = new self(
                $languageProvider,
                $httpClient,
                $requestFactory,
                $streamFactory,
                $platformInfo,
                $configurationManager,
                $apiKey,
                $sandboxMode,
                $customApiBaseUrl
            );
        }

        return self::$instance;
    }

    /**
     * Resets the singleton instance, allowing a new instance to be created.
     * Useful for testing or when settings need to be refreshed.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Returns product types for the given list type as [typeCode => typeName], or null on API error.
     *
     * @return array<string, string>|null
     */
    public function getProductTypes(?string $listType = null): ?array
    {
        $listType ??= ProductListType::PAYWALL->value;
        $language = $this->languageProvider->getLanguage();
        $cacheKey = "product_types.$listType.$language";

        if (array_key_exists($cacheKey, $this->productTypesCache)) {
            return $this->productTypesCache[$cacheKey];
        }

        if (($cached = CacheManager::get($cacheKey)) !== null) {
            $this->productTypesCache[$cacheKey] = is_array($cached) ? $cached : [];

            return $this->productTypesCache[$cacheKey];
        }

        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $listTypeEnum = ProductListType::from($listType);
            $response = $this->getApiClient()->getProductTypes($listTypeEnum);
            $productTypes = $response->productTypesWithNames;
            $cacheTtl = (int) $response->getHeader('Cache-TTL', '0');

            CacheManager::set($cacheKey, $productTypes, $cacheTtl, ['admin_product_types']);

            $this->productTypesCache[$cacheKey] = $productTypes;

            return $productTypes;
        } catch (Throwable) {
            // Handle API errors gracefully.
            return null;
        }
    }

    /**
     * Returns widget types as [typeCode => typeName], or null on API error.
     *
     * @return array<string, string>|null
     */
    public function getWidgetTypes(): ?array
    {
        $language = $this->languageProvider->getLanguage();
        $cacheKey = "widget_types.$language";

        if ($this->widgetTypesCache !== null) {
            return $this->widgetTypesCache;
        }

        if (($cached = CacheManager::get($cacheKey)) !== null) {
            $this->widgetTypesCache = is_array($cached) ? $cached : [];

            return $this->widgetTypesCache;
        }

        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = $this->getApiClient()->getWidgetTypes();
            $widgetTypes = $response->widgetTypesWithNames;
            $cacheTtl = (int) $response->getHeader('Cache-TTL', '0');

            CacheManager::set($cacheKey, $widgetTypes, $cacheTtl, ['admin_widget_types']);

            $this->widgetTypesCache = $widgetTypes;

            return $widgetTypes;
        } catch (Throwable) {
            // Handle API request failure gracefully.
            return null;
        }
    }

    /**
     * Returns available creditors keyed by product type code, or null on API error / missing API key.
     *
     * @return array<string, string[]>|null
     */
    public function getCreditors(): ?array
    {
        if ($this->creditorsCache !== null) {
            return $this->creditorsCache;
        }

        $cacheKey = 'creditors';

        if (($cached = CacheManager::get($cacheKey)) !== null) {
            $this->creditorsCache = is_array($cached) ? $cached : [];

            return $this->creditorsCache;
        }

        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = $this->getApiClient()->getCreditors();
            $creditors = $response->creditors;
            $cacheTtl = (int) $response->getHeader('Cache-TTL', '0');

            CacheManager::set($cacheKey, $creditors, $cacheTtl, ['admin_product_types']);

            $this->creditorsCache = $creditors;

            return $creditors;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns the per-product-type term constraints as DTOs, or null when none are configured.
     *
     * @return \Comfino\Api\Dto\Payment\AllowedProductConfig[]|null
     */
    public function getAllowedProductsConfig(): ?array
    {
        if ($this->allowedProductsConfigCache !== null) {
            return $this->allowedProductsConfigCache;
        }

        if ($this->configurationManager === null) {
            return null;
        }

        $this->allowedProductsConfigCache = AllowedProductsConfigBuilder::fromPersistedArray(
            $this->configurationManager->getConfigurationValue('COMFINO_ALLOWED_PRODUCTS_CONFIG')
        ) ?? [];

        return $this->allowedProductsConfigCache !== [] ? $this->allowedProductsConfigCache : null;
    }

    /**
     * Returns the per-product-type term constraints as a plain array for frontend embedding, or null when none are
     * configured.
     *
     * @return array<int, array{type: string, maxTerm?: int, minTerm?: int, terms?: int[]}>|null
     */
    public function getAllowedProductsConfigForFrontend(): ?array
    {
        return AllowedProductsConfigBuilder::toFrontendArray($this->getAllowedProductsConfig());
    }

    /**
     * Returns product types as a select list [['value' => code, 'label' => name], ...].
     * Returns a single error entry when the API is unavailable.
     *
     * @return array<int, array<string, string>>
     */
    public function getProductTypesSelectList(?string $listType = null): array
    {
        $productTypes = $this->getProductTypes($listType);

        if ($productTypes === null) {
            return [['value' => '', 'label' => 'Save the API key first to load offer types.']];
        }

        return array_map(
            static fn (string $code, string $name): array => ['value' => $code, 'label' => $name],
            array_keys($productTypes),
            $productTypes
        );
    }

    /**
     * Returns widget types as a select list.
     * Returns a single error entry when the API is unavailable.
     *
     * @return array<int, array<string, string>>
     */
    public function getWidgetTypesSelectList(): array
    {
        $widgetTypes = $this->getWidgetTypes();

        if ($widgetTypes === null) {
            return [['value' => '', 'label' => 'Save the API key first to load widget types.']];
        }

        return array_map(
            static fn (string $code, string $name): array => ['value' => $code, 'label' => $name],
            array_keys($widgetTypes),
            $widgetTypes
        );
    }

    /**
     * Returns product type code strings.
     *
     * @return string[]
     */
    public function getProductTypesStrings(?string $listType = null): array
    {
        return ($types = $this->getProductTypes($listType)) !== null ? array_keys($types) : [];
    }

    /**
     * Returns allowed product types after applying configured filters.
     *
     * Returns null when no filters are active (all types allowed).
     * Returns [] when all types are filtered out.
     *
     * @return LoanTypeInterface[]|null
     */
    public function getAllowedProductTypes(Cart $cart, string $listType): ?array
    {
        $filterManager = clone ProductTypeFilterManager::getInstance();

        if (!$filterManager->filtersActive()) {
            return null;
        }

        $productTypes = $this->getProductTypes($listType);

        if ($productTypes === null) {
            return null;
        }

        $availableProductTypes = array_map(
            static fn (string $productTypeCode): LoanTypeInterface => LoanType::fromApiValue($productTypeCode),
            array_keys($productTypes)
        );

        $allowedProductTypes = $filterManager->getAllowedProductTypes($availableProductTypes, $cart);

        return count($availableProductTypes) !== count($allowedProductTypes) ? $allowedProductTypes : null;
    }

    /**
     * Returns the API client instance, creating it if necessary.
     */
    private function getApiClient(): Client
    {
        if ($this->apiClient === null) {
            $factory = new ApiClientFactory();

            if ($this->platformInfo !== null) {
                // Create API client from platform info.
                $this->apiClient = $factory->createClientFromPlatformInfo(
                    $this->platformInfo,
                    $this->apiKey,
                    $this->sandboxMode,
                    $this->httpClient,
                    $this->requestFactory,
                    $this->streamFactory
                );

                if ($this->customApiBaseUrl !== null) {
                    $this->apiClient->setCustomApiBaseUrl($this->customApiBaseUrl);
                }
            } else {
                // Create API client with default settings.
                $this->apiClient = $factory->createClient(
                    $this->httpClient,
                    $this->requestFactory,
                    $this->streamFactory,
                    $this->apiKey,
                    apiBaseUrl: $this->customApiBaseUrl,
                    apiLanguage: $this->languageProvider->getLanguage()
                );

                if ($this->sandboxMode) {
                    $this->apiClient->enableSandboxMode();
                }
            }
        }

        return $this->apiClient;
    }
}
