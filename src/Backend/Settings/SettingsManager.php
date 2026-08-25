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
use Comfino\Api\Dto\Payment\AllowedProductConfig;
use Comfino\Backend\Cache\CacheManager;
use Comfino\Backend\Cache\ScopedCache;
use Comfino\Backend\Configuration\ConfigurationManager;
use Comfino\Backend\Factory\ApiClientFactory;
use Comfino\Backend\Payment\AllowedProductsConfigBuilder;
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Platform\ConnectorInfoInterface;
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
 * Fetches product and widget types from the Comfino API, caches them, and applies product-type filters.
 *
 * One instance per tenant scope, following the {@see ConfigurationManager} pattern. This used to be a process-wide
 * singleton, which meant the first tenant to ask for it in a long-lived process fixed the API key, the sandbox flag
 * and the API base URL for every tenant after it - and, because the instance also holds a request-lifetime memo of
 * every lookup it has made, served the first tenant's product types, widget types and creditor lists to everyone. The
 * scope is also the cache partition: entries are read and written through {@see ScopedCache}, so two tenants sharing a
 * cache backend cannot see each other's entries.
 */
final class SettingsManager
{
    /**
     * Lifetime (in seconds) of the negative cache entry written after a failed API lookup. Kept short so that a
     * recovered API is picked up quickly, but long enough to keep a single outage from being re-probed by every
     * incoming storefront request.
     */
    private const FAILED_LOOKUP_CACHE_TTL = 60;

    /** @var array<string, self> One instance per tenant scope, keyed by the scope string. */
    private static array $instances = [];
    private ?Client $apiClient = null;
    private ?ScopedCache $cache = null;

    /** @var array<string, bool> Cache keys whose API lookup already failed within the current request */
    private array $failedLookups = [];

    /** @var array<string, array<string, string>|null> Keyed by "listType.language" */
    private array $productTypesCache = [];
    /** @var array<string, string>|null null = not yet fetched */
    private ?array $widgetTypesCache = null;
    /** @var array<string, string[]>|null null = not yet fetched */
    private ?array $creditorsCache = null;
    /** @var AllowedProductConfig[]|null */
    private ?array $allowedProductsConfigCache = null;

    private function __construct(
        private readonly LanguageProviderInterface $languageProvider,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?ConnectorInfoInterface $platformInfo,
        private readonly ?ConfigurationManager $configurationManager,
        private readonly string $apiKey,
        private readonly bool $sandboxMode,
        private readonly ?string $customApiBaseUrl = null,
        private readonly string $scope = ''
    ) {
    }

    /**
     * Retrieves a per-scope instance of the SettingsManager.
     *
     * @param LanguageProviderInterface $languageProvider Resolves the language every API lookup is keyed by
     * @param ClientInterface $httpClient PSR-18 HTTP client implementation
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param ConnectorInfoInterface|null $platformInfo Integration metadata, or null to build a bare client
     * @param ConfigurationManager|null $configurationManager Source of the persisted allowed-products config
     * @param string $apiKey This tenant's API key
     * @param bool $sandboxMode Whether this tenant talks to the sandbox host
     * @param string|null $customApiBaseUrl Custom API base URL, or null for the default
     * @param string $scope Tenant scope discriminator (empty string = global/default scope)
     *
     * @return self The instance bound to $scope
     */
    public static function getInstance(
        LanguageProviderInterface $languageProvider,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?ConnectorInfoInterface $platformInfo,
        ?ConfigurationManager $configurationManager,
        string $apiKey,
        bool $sandboxMode,
        ?string $customApiBaseUrl = null,
        string $scope = ''
    ): self {
        if (!isset(self::$instances[$scope])) {
            self::$instances[$scope] = new self(
                $languageProvider,
                $httpClient,
                $requestFactory,
                $streamFactory,
                $platformInfo,
                $configurationManager,
                $apiKey,
                $sandboxMode,
                $customApiBaseUrl,
                $scope
            );
        }

        return self::$instances[$scope];
    }

    /**
     * Returns the scopes that currently hold a cached instance.
     *
     * Diagnostics for the retention of every scope-addressed class in the SDK shares: the cache keeps the first
     * instance built for a scope and never evicts it, so a host that builds per request must release per request.
     * Asserting this is empty at the end of a unit of work turns a forgotten `reset()` into a failing test rather
     * than a slow leak.
     *
     * @return string[] Scope discriminators, in insertion order
     */
    public static function scopes(): array
    {
        return array_keys(self::$instances);
    }

    /**
     * Returns how many scopes hold a cached instance.
     */
    public static function scopeCount(): int
    {
        return count(self::$instances);
    }

    /**
     * Drops cached instances, so the next call builds a fresh one. Useful for testing or when settings change.
     *
     * @param string|null $scope When given, only the instance bound to this scope is dropped; when null (default), all
     *                           cached instances (every scope) are dropped.
     */
    public static function reset(?string $scope = null): void
    {
        if ($scope === null) {
            self::$instances = [];
        } else {
            unset(self::$instances[$scope]);
        }
    }

    /**
     * Returns the tenant scope this instance is bound to.
     */
    public function getScope(): string
    {
        return $this->scope;
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

        if (($cached = $this->cache()->get($cacheKey)) !== null) {
            $this->productTypesCache[$cacheKey] = is_array($cached) ? $cached : [];

            return $this->productTypesCache[$cacheKey];
        }

        if (empty($this->apiKey) || $this->lookupRecentlyFailed($cacheKey)) {
            return null;
        }

        try {
            $listTypeEnum = ProductListType::from($listType);
            $response = $this->getApiClient()->getProductTypes($listTypeEnum);
            $productTypes = $response->productTypesWithNames;
            $cacheTtl = (int) $response->getHeader('Cache-TTL', '0');

            $this->cache()->set($cacheKey, $productTypes, $cacheTtl, ['admin_product_types']);

            $this->productTypesCache[$cacheKey] = $productTypes;

            return $productTypes;
        } catch (Throwable) {
            // Handle API errors gracefully and keep the next requests from repeating the failed lookup.
            $this->markLookupFailed($cacheKey, ['admin_product_types']);

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

        if (($cached = $this->cache()->get($cacheKey)) !== null) {
            $this->widgetTypesCache = is_array($cached) ? $cached : [];

            return $this->widgetTypesCache;
        }

        if (empty($this->apiKey) || $this->lookupRecentlyFailed($cacheKey)) {
            return null;
        }

        try {
            $response = $this->getApiClient()->getWidgetTypes();
            $widgetTypes = $response->widgetTypesWithNames;
            $cacheTtl = (int) $response->getHeader('Cache-TTL', '0');

            $this->cache()->set($cacheKey, $widgetTypes, $cacheTtl, ['admin_widget_types']);

            $this->widgetTypesCache = $widgetTypes;

            return $widgetTypes;
        } catch (Throwable) {
            // Handle API request failure gracefully and keep the next requests from repeating the failed lookup.
            $this->markLookupFailed($cacheKey, ['admin_widget_types']);

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

        if (($cached = $this->cache()->get($cacheKey)) !== null) {
            $this->creditorsCache = is_array($cached) ? $cached : [];

            return $this->creditorsCache;
        }

        if (empty($this->apiKey) || $this->lookupRecentlyFailed($cacheKey)) {
            return null;
        }

        try {
            $response = $this->getApiClient()->getCreditors();
            $creditors = $response->creditors;
            $cacheTtl = (int) $response->getHeader('Cache-TTL', '0');

            $this->cache()->set($cacheKey, $creditors, $cacheTtl, ['admin_product_types']);

            $this->creditorsCache = $creditors;

            return $creditors;
        } catch (Throwable) {
            // Handle API request failure gracefully and keep the next requests from repeating the failed lookup.
            $this->markLookupFailed($cacheKey, ['admin_product_types']);

            return null;
        }
    }

    /**
     * Returns the per-product-type term constraints as DTOs or null when none are configured.
     *
     * @return AllowedProductConfig[]|null
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
        $filterManager = clone ProductTypeFilterManager::getInstance($this->scope);

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

    /**
     * Tells whether the API lookup behind the given cache key has already failed, either earlier in the current
     * request or recently enough for its negative cache entry to still be valid. Without this check every storefront
     * render repeats a failing lookup and pays the full retry escalation of the API client, which is what turns a
     * Comfino API outage into shop-wide worker exhaustion.
     *
     * @param string $cacheKey Cache key of the positive result the lookup would have produced
     */
    private function lookupRecentlyFailed(string $cacheKey): bool
    {
        if (isset($this->failedLookups[$cacheKey])) {
            return true;
        }

        if ($this->cache()->get(self::failedLookupCacheKey($cacheKey)) !== null) {
            $this->failedLookups[$cacheKey] = true;

            return true;
        }

        return false;
    }

    /**
     * Marks the API lookup behind the given cache key as failed, for the remainder of the current request and for the
     * next self::FAILED_LOOKUP_CACHE_TTL seconds. The negative entry carries the same cache tags as the positive one,
     * so an explicit cache invalidation clears it too, and the next request retries the lookup immediately.
     *
     * @param string $cacheKey Cache key of the positive result the lookup would have produced
     * @param string[] $tags Cache tags of the positive result
     */
    private function markLookupFailed(string $cacheKey, array $tags): void
    {
        $this->failedLookups[$cacheKey] = true;

        $this->cache()->set(self::failedLookupCacheKey($cacheKey), true, self::FAILED_LOOKUP_CACHE_TTL, $tags);
    }

    /**
     * Returns the cache key holding the negative entry for the given lookup.
     */
    private static function failedLookupCacheKey(string $cacheKey): string
    {
        return "$cacheKey.lookup_failed";
    }

    /**
     * Returns this tenant's cache view, resolved once per instance.
     *
     * Every read and write goes through it rather than through the {@see CacheManager} statics, so the entries this
     * manager touches are partitioned by the same scope that selected the instance - a tenant cannot be served a
     * cached product-type list that was fetched with a different tenant's API key.
     */
    private function cache(): ScopedCache
    {
        return $this->cache ??= CacheManager::forScope($this->scope);
    }
}
