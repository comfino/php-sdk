<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Settings
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
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
use Comfino\Backend\Payment\Filter\FilterByCartValueLowerLimit;
use Comfino\Backend\Payment\Filter\FilterByExcludedCategory;
use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Platform\ConnectorInfoInterface;
use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Cart;
use Comfino\Shop\Product\CategoryFilter;
use Comfino\Shop\Product\CategoryTreeProviderInterface;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Consolidated product and widget types manager.
 *
 * Fetches product and widget types from the ComfinoPay API, caches them, and applies product-type filters.
 *
 * One instance per tenant scope, following the {@see ConfigurationManager} pattern: a process-wide singleton would
 * let the first tenant to ask for it in a long-lived process fix the API key, the sandbox flag and the API base URL
 * for every tenant after it - and, because the instance also holds a request-lifetime memo of every lookup it has
 * made, serve the first tenant's product types, widget types and creditor lists to everyone. The scope is also the
 * cache partition: entries are read and written through {@see ScopedCache}, so two tenants sharing a cache backend
 * cannot see each other's entries.
 */
final class SettingsManager
{
    /**
     * Lifetime (in seconds) of the negative cache entry written after a failed API lookup. Kept short so that a
     * recovered API is picked up quickly, but long enough to keep a single outage from being re-probed by every
     * incoming storefront request.
     */
    public const DEFAULT_FAILED_LOOKUP_CACHE_TTL = 60;

    /** Cache tag of the remote flags map; part of {@see \Comfino\Enum\CacheItemType} so a signed invalidation can clear it. */
    public const REMOTE_FLAGS_CACHE_TAG = 'remote_flags';

    /** Longest time, in seconds, the remote flags map is served without asking the API again (also the default). */
    public const REMOTE_FLAGS_MAX_CACHE_TTL = 86400;

    /**
     * Lifetime, in seconds, of the product types, widget types and creditors when the API names none (a missing,
     * zero or negative `Comfino-Cache-TTL`, e.g. stripped by a proxy). Without it such an entry would never expire; a
     * signed cache invalidation still clears it sooner.
     */
    public const FALLBACK_CACHE_TTL = 3600;

    /** How long, in seconds, a failed flags lookup is not repeated. */
    public const REMOTE_FLAGS_FAILED_LOOKUP_CACHE_TTL = 300;

    private const REMOTE_FLAGS_CACHE_KEY = 'remote_flags';
    private const REMOTE_FLAG_NAME_PATTERN = '/^[A-Z0-9_]{1,64}$/';

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
    /** @var array<string, ProductTypeFilterManager> Filter managers built from the configuration, keyed by list type */
    private array $configuredFilterManagers = [];
    private ?CategoryTreeProviderInterface $categoryTreeProvider = null;
    private int $failedLookupCacheTtl = self::DEFAULT_FAILED_LOOKUP_CACHE_TTL;

    /** @var array<string, mixed>|null Remote flags map of the current request, once read */
    private ?array $remoteFlagsCache = null;

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
     * Diagnostics for the retention of every scope-addressed class in the SDK shares: the cache keeps the first instance
     * built for a scope and never evicts it, so a host that builds per request must release per request. Asserting this
     * is empty at the end of a unit of work turns a forgotten `reset()` into a failing test rather than a slow leak.
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
            $response = $this->getApiClient()->getProductTypes(ProductListType::from($listType));
            $productTypes = $response->productTypesWithNames;
            $cacheTtl = self::resolveCacheTtl((int) $response->getHeader('Comfino-Cache-TTL', '0'));

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
            $cacheTtl = self::resolveCacheTtl((int) $response->getHeader('Comfino-Cache-TTL', '0'));

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
            $cacheTtl = self::resolveCacheTtl((int) $response->getHeader('Comfino-Cache-TTL', '0'));

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

        return array_map(static fn (string $code, string $name): array => ['value' => $code, 'label' => $name], array_keys($productTypes), $productTypes);
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

        return array_map(static fn (string $code, string $name): array => ['value' => $code, 'label' => $name], array_keys($widgetTypes), $widgetTypes);
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
     * Sets how long, in seconds, a failed API lookup is not repeated (default {@see self::DEFAULT_FAILED_LOOKUP_CACHE_TTL}).
     * Lengthen it while the host's circuit breaker is open; shorten it in tests.
     */
    public function setFailedLookupCacheTtl(int $seconds): void
    {
        $this->failedLookupCacheTtl = max(1, $seconds);
    }

    /**
     * Sets the category tree source for the category-exclusion filter. Required when COMFINO_PRODUCT_CATEGORY_FILTERS
     * may be configured; the tree is requested only when it is.
     */
    public function setCategoryTreeProvider(CategoryTreeProviderInterface $categoryTreeProvider): void
    {
        $this->categoryTreeProvider = $categoryTreeProvider;
        $this->configuredFilterManagers = [];
    }

    /**
     * Returns allowed product types after applying the filters registered on the scope's {@see ProductTypeFilterManager}
     * plus the filters built from the configuration ({@see self::buildConfiguredFilters()}).
     *
     * Returns null when no filter is active, or when the filters leave every available type (all types allowed).
     * Returns [] when all types are filtered out. With $returnOnlyArray the filtered list is returned even when it
     * equals the available list; null is then returned only when no filter is active.
     *
     * Unlike {@see self::getAllowedProductTypes()}, a failed product-type lookup counts as "no types available".
     *
     * @param Cart $cart Cart the filters are evaluated against
     * @param string $listType Product list type ({@see ProductListType} value)
     * @param bool $returnOnlyArray Whether to return the filtered list even when nothing was filtered out
     *
     * @return LoanTypeInterface[]|null
     *
     * @throws LogicException When a category filter is configured but no category tree provider is set
     */
    public function getAllowedProductTypesFiltered(Cart $cart, string $listType, bool $returnOnlyArray = false): ?array
    {
        $filterManager = $this->getConfiguredFilterManager($listType);

        if (!$filterManager->filtersActive()) {
            return null;
        }

        $availableProductTypes = array_map(
            static fn (string $productTypeCode): LoanTypeInterface => LoanType::fromApiValue($productTypeCode),
            $this->getProductTypesStrings($listType)
        );

        $allowedProductTypes = $filterManager->getAllowedProductTypes($availableProductTypes, $cart);

        if ($returnOnlyArray) {
            return $allowedProductTypes;
        }

        return count($availableProductTypes) !== count($allowedProductTypes) ? $allowedProductTypes : null;
    }

    /**
     * Returns the product types to offer for the cart: **never null**. No active filter (or one that removes nothing)
     * gives the full available list; a failed or empty product-type lookup gives [].
     *
     * `[]` means "offer nothing" - do not treat it, or a failed lookup, as "allow all" when validating a submitted loan
     * type ({@see \Comfino\Shop\Order\OrderValidator::validateLoanSelection()}).
     *
     * @param string $listType Product list type ({@see ProductListType} value)
     * @param Cart $cart Cart the filters are evaluated against
     *
     * @return LoanTypeInterface[]
     *
     * @throws LogicException When a category filter is configured but no category tree provider is set
     */
    public function resolveAllowedProductTypes(string $listType, Cart $cart): array
    {
        if (($productTypes = $this->getProductTypesStrings($listType)) === []) {
            return [];
        }

        return $this->getAllowedProductTypesFiltered($cart, $listType, true) ?? array_map(
            static fn (string $productTypeCode): LoanTypeInterface => LoanType::fromApiValue($productTypeCode),
            $productTypes
        );
    }

    /**
     * Returns a copy of the scope's {@see ProductTypeFilterManager} with the configured filters added, built once per
     * list type. Exposed so the host can inspect the active filters (e.g. for debug logging).
     *
     * @param string $listType Product list type ({@see ProductListType} value)
     *
     * @throws LogicException When a category filter is configured but no category tree provider is set
     */
    public function getConfiguredFilterManager(string $listType): ProductTypeFilterManager
    {
        if (!isset($this->configuredFilterManagers[$listType])) {
            $filterManager = clone ProductTypeFilterManager::getInstance($this->scope);

            foreach ($this->buildConfiguredFilters($listType) as $filter) {
                $filterManager->addFilter($filter);
            }

            $this->configuredFilterManagers[$listType] = $filterManager;
        }

        return $this->configuredFilterManagers[$listType];
    }

    /**
     * Builds the product type filters configured by the merchant:
     *  - {@see FilterByCartValueLowerLimit} from COMFINO_MINIMAL_CART_AMOUNT (applies to every available type),
     *  - {@see FilterByExcludedCategory} from COMFINO_PRODUCT_CATEGORY_FILTERS.
     *
     * @param string $listType Product list type ({@see ProductListType} value)
     *
     * @return ProductTypeFilterInterface[]
     *
     * @throws LogicException When a category filter is configured but no category tree provider is set
     */
    public function buildConfiguredFilters(string $listType): array
    {
        $filters = [];

        if (($minimalCartAmount = $this->getMinimalCartAmount()) > 0) {
            $productTypes = $this->getProductTypesStrings($listType);

            $filters[] = new FilterByCartValueLowerLimit(array_fill_keys($productTypes, $minimalCartAmount));
        }

        if (self::productCategoryFiltersActive($productCategoryFilters = $this->getProductCategoryFilters())) {
            if ($this->categoryTreeProvider === null) {
                throw new LogicException(
                    'Category filters are configured but no category tree provider is set - call setCategoryTreeProvider().'
                );
            }

            $filters[] = new FilterByExcludedCategory(
                new CategoryFilter($this->categoryTreeProvider->getCategoryTree()),
                self::normalizeCategoryFilters($productCategoryFilters)
            );
        }

        return $filters;
    }

    /**
     * Returns the minimal cart amount (COMFINO_MINIMAL_CART_AMOUNT, stored in the main currency unit) in cents, or 0
     * when no limit is configured.
     */
    public function getMinimalCartAmount(): int
    {
        $minimalCartAmount = $this->configurationManager?->getOptionWithDefault('COMFINO_MINIMAL_CART_AMOUNT');

        return is_numeric($minimalCartAmount) ? max(0, (int) round((float) $minimalCartAmount * 100)) : 0;
    }

    /**
     * Returns the excluded category IDs keyed by product type (COMFINO_PRODUCT_CATEGORY_FILTERS). A value persisted as a
     * comma-separated string is split into a list.
     *
     * @return array<string|int, array<int>|string>
     */
    public function getProductCategoryFilters(): array
    {
        return self::parseListOption($this->configurationManager?->getOptionWithDefault('COMFINO_PRODUCT_CATEGORY_FILTERS'));
    }

    /**
     * Returns the product types the admin category-filter UI offers (COMFINO_CAT_FILTER_AVAIL_PROD_TYPES). A value
     * persisted as a comma-separated string is split into a list.
     *
     * @return array<string|int, mixed>
     */
    public function getProductCategoryFiltersAvailProductTypes(): array
    {
        return self::parseListOption($this->configurationManager?->getOptionWithDefault('COMFINO_CAT_FILTER_AVAIL_PROD_TYPES'));
    }

    /**
     * Returns the paywall product types offered by the admin category-filter UI: the available types intersected with
     * COMFINO_CAT_FILTER_AVAIL_PROD_TYPES, or all of them when the intersection is empty.
     *
     * @return array<string, string> [typeCode => typeName], empty on API error
     */
    public function getCatFilterAvailProdTypes(): array
    {
        if (empty($productTypes = $this->getProductTypes(ProductListType::PAYWALL->value))) {
            return [];
        }

        $configuredTypes = array_fill_keys(
            array_filter($this->getProductCategoryFiltersAvailProductTypes(), 'is_string'),
            null
        );

        return array_intersect_key($productTypes, $configuredTypes) ?: $productTypes;
    }

    /**
     * Tells whether any product type has at least one excluded category.
     *
     * @param array<string|int, array<int|string>|string> $productCategoryFilters As returned by {@see self::getProductCategoryFilters()}
     */
    public static function productCategoryFiltersActive(array $productCategoryFilters): bool
    {
        foreach ($productCategoryFilters as $excludedCategoryIds) {
            if (!empty($excludedCategoryIds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the shop's remote flags and their attributes as the API sent them, keyed by flag name.
     *
     * The map is kept verbatim (the `PROMO` entry included) in the tenant's cache under {@see REMOTE_FLAGS_CACHE_TAG}, for the
     * `Comfino-Cache-TTL` the API names when that is shorter than {@see REMOTE_FLAGS_MAX_CACHE_TTL}, else for that maximum. The API is
     * asked only on a cache miss. A failed lookup returns an empty map, is remembered for {@see REMOTE_FLAGS_FAILED_LOOKUP_CACHE_TTL}
     * seconds and is not reported as an error: the flags only decorate checkout.
     *
     * @return array<string, mixed>
     */
    public function getRemoteFlagAttributes(): array
    {
        if ($this->remoteFlagsCache !== null) {
            return $this->remoteFlagsCache;
        }

        if (is_array($cached = $this->cache()->get(self::REMOTE_FLAGS_CACHE_KEY))) {
            return $this->remoteFlagsCache = $cached;
        }

        if (empty($this->apiKey) || $this->lookupRecentlyFailed(self::REMOTE_FLAGS_CACHE_KEY)) {
            return $this->remoteFlagsCache = [];
        }

        try {
            $response = $this->getApiClient()->getUserSettings();
            $flags = self::sanitizeRemoteFlagAttributes($response->flags);
            $headerTtl = (int) $response->getHeader('Comfino-Cache-TTL', '0');
            $cacheTtl = $headerTtl > 0 ? min($headerTtl, self::REMOTE_FLAGS_MAX_CACHE_TTL) : self::REMOTE_FLAGS_MAX_CACHE_TTL;

            $this->cache()->set(self::REMOTE_FLAGS_CACHE_KEY, $flags, $cacheTtl, [self::REMOTE_FLAGS_CACHE_TAG]);

            return $this->remoteFlagsCache = $flags;
        } catch (Throwable) {
            $this->markLookupFailed(
                self::REMOTE_FLAGS_CACHE_KEY,
                [self::REMOTE_FLAGS_CACHE_TAG],
                self::REMOTE_FLAGS_FAILED_LOOKUP_CACHE_TTL
            );

            return $this->remoteFlagsCache = [];
        }
    }

    /**
     * Returns the flags map for the checkout payload: the cached map as is, with `null` when it is empty (an empty PHP array would
     * encode as a JSON array, not an object). The frontend SDK reads `PROMO` itself and decides what to show.
     *
     * @return array<string, mixed>|null
     */
    public function getCheckoutFlagAttributes(): ?array
    {
        return $this->getRemoteFlagAttributes() ?: null;
    }

    /**
     * Returns the names of the cached flags without `PROMO`, which is a derived entry the API never lists among the stored flags.
     *
     * @return string[]
     */
    public function getRemoteFlagNames(): array
    {
        return array_values(array_filter(
            array_keys($this->getRemoteFlagAttributes()),
            static fn (string|int $name): bool => $name !== 'PROMO'
        ));
    }

    /**
     * The cache lifetime to use: the API's `Comfino-Cache-TTL` when positive, else {@see FALLBACK_CACHE_TTL}.
     */
    private static function resolveCacheTtl(int $headerTtl): int
    {
        return $headerTtl > 0 ? $headerTtl : self::FALLBACK_CACHE_TTL;
    }

    /**
     * Keeps the entries of a flags map whose name is upper case letters, digits and underscores (1 to 64 characters) and whose value
     * encodes as JSON. Values pass through unchanged, whatever their type, and nested keys are not checked: what a flag means belongs
     * to the API and the frontend SDK.
     *
     * @param array<array-key, mixed> $flags
     *
     * @return array<string, mixed>
     */
    public static function sanitizeRemoteFlagAttributes(array $flags): array
    {
        $sanitized = [];

        foreach ($flags as $name => $value) {
            if (!is_string($name) || preg_match(self::REMOTE_FLAG_NAME_PATTERN, $name) !== 1) {
                continue;
            }

            if (json_encode($value) === false) {
                continue;
            }

            $sanitized[$name] = $value;
        }

        return $sanitized;
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
     * ComfinoPay API outage into shop-wide worker exhaustion.
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
     * next $this->failedLookupCacheTtl seconds. The negative entry carries the same cache tags as the positive one,
     * so an explicit cache invalidation clears it too, and the next request retries the lookup immediately.
     *
     * @param string $cacheKey Cache key of the positive result the lookup would have produced
     * @param string[] $tags Cache tags of the positive result
     * @param int|null $ttl Seconds the failure is remembered; null = {@see $failedLookupCacheTtl}
     */
    private function markLookupFailed(string $cacheKey, array $tags, ?int $ttl = null): void
    {
        $this->failedLookups[$cacheKey] = true;

        $this->cache()->set(self::failedLookupCacheKey($cacheKey), true, $ttl ?? $this->failedLookupCacheTtl, $tags);
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

    /**
     * Keeps the product-type entries with at least one category ID, as integers. Entries without a product-type key
     * (a legacy comma-separated value) cannot match any type and are dropped; a comma-separated ID string is split.
     *
     * @param array<string|int, mixed> $productCategoryFilters
     *
     * @return array<string, int[]>
     */
    private static function normalizeCategoryFilters(array $productCategoryFilters): array
    {
        $normalized = [];

        foreach ($productCategoryFilters as $productType => $categoryIds) {
            if (!is_string($productType)) {
                continue;
            }

            $categoryIds = array_filter(self::parseListOption($categoryIds), 'is_numeric');

            if ($categoryIds !== []) {
                $normalized[$productType] = array_values(array_map('intval', $categoryIds));
            }
        }

        return $normalized;
    }

    /**
     * @return array<string|int, mixed>
     */
    private static function parseListOption(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_map('trim', explode(',', $value));
    }
}
