# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0-beta1] - 2026-06-02

### Breaking Changes
- **Cache system refactoring:** `CacheInvalidate` constructor now requires `Psr\Cache\CacheItemPoolInterface` instead of `Cache\TagInterop\TaggableCacheItemPoolInterface` (removed dependency on abandoned `cache/tag-interop` package).
  - For tag-based cache invalidation, the injected pool must also implement `Symfony\Contracts\Cache\TagAwareCacheInterface` (e.g., Symfony's `TagAwareAdapter`).
  - If the pool does not support tagging, `CacheInvalidate` silently skips the invalidation step.
  - See [UPGRADE.md](UPGRADE.md) for migration guide.
- Bumped minimum `psr/cache` from `^2.0 || ^3.0` to `^1.0 || ^2.0 || ^3.0` for broader compatibility with legacy stacks.
- **Widget script class renames:** `WidgetInitScript` renamed to `WidgetFrontendInitScript`; `WidgetInitScriptHelper` renamed to `WidgetFrontendInitScriptHelper`. Update all class references and constructor calls in your integration.
- **`WidgetSdkInitScriptHelper` parameter changes** (vs. the former `WidgetInitScriptHelper` when targeting `comfino-sdk.min.js`): removed `WIDGET_PRICE_SELECTOR`, `WIDGET_PRICE_OBSERVER_SELECTOR`, `WIDGET_PRICE_OBSERVER_LEVEL`, and `EMBED_METHOD`; added `ENVIRONMENT` (`'sandbox'|'production'`) and `HAS_PRICE_INPUT` (bool); `PRODUCT_PRICE` is now an integer in grosze (smallest currency unit) instead of a PLN float.
- **`SettingsManager::getInstance()` signature change:** a new `?ConfigurationManager $configurationManager` parameter has been inserted as the 6th argument (between `?PlatformInfoInterface` and `string $apiKey`). All existing call sites must pass `null` or a configured `ConfigurationManager` instance in that position.
- **`OrderInterface::getAllowedProductsConfig(): ?array` added:** third-party implementations of `OrderInterface` must now implement this method. The safe default is to return `null`.

### Security Fixes
- **XSS vulnerabilities in widget initialization and logo rendering** — Fixed unescaped JavaScript and HTML injection in `WidgetInitScriptHelper` and `FrontendHelper`. Template values are now properly JSON-encoded and HTML-escaped.
- **Webhook request validation** — Added optional replay attack mitigation and rate-limiting hooks to `WebhookManager`. Introduced a 1 MB request body size limit to prevent memory exhaustion.
- **Cryptographic improvements** — Increased token entropy from 80 to 128 bits, replaced `uniqid()` with `random_bytes()` for secure random IDs, upgraded MD5 checksums to SHA-256.

### Added
- **Creditors support:** `SettingsManager::getCreditors()` fetches available creditors keyed by product type from the Comfino API, with in-memory and `CacheManager` caching under the `'creditors'` key (tagged `admin_product_types`). Returns `null` when the API key is absent or the call fails; an empty array is a valid "no creditors configured" response.
- **Allowed-products configuration:** `SettingsManager::getAllowedProductsConfig()` reads the `COMFINO_ALLOWED_PRODUCTS_CONFIG` key from the injected `ConfigurationManager` and returns a `Comfino\Api\Dto\Payment\AllowedProductConfig[]` DTO list (or `null` when unconfigured). `SettingsManager::getAllowedProductsConfigForFrontend()` returns the same data as a plain array suitable for JSON / `window.comfinoPaywallData` embedding.
- **`AllowedProductsConfigBuilder`** (`Comfino\Backend\Payment`): static helper that converts the persisted array shape `[{type, maxTerm?, minTerm?, terms?}, …]` to `AllowedProductConfig[]` via `fromPersistedArray()`, and the reverse via `toFrontendArray()`. Malformed or type-less entries are silently skipped; returns `null` when the result is empty.
- **Paywall creditors & term constraints:** `PaywallConfig` gains two new optional readonly properties — `?array $creditors` and `?array $allowedProductsConfig` — both included in `getAsArray()`. `PaywallConfigBuilder::buildConfig()` accepts matching optional parameters at the end of its signature (backward compatible).
- **`Order::getAllowedProductsConfig()`:** the `Order` class accepts an optional `?array $allowedProductsConfig` constructor parameter (last position, defaults to `null`) and exposes it via the new `getAllowedProductsConfig(): ?array` getter defined on `OrderInterface`.
- **`OrderFactory::createOrder()` extension:** new optional `?array $allowedProductsConfig = null` parameter (last position). When provided, it is threaded through to the `Order` constructor so downstream code can pass `$order->getAllowedProductsConfig()` directly to `AbstractClient::createOrder()` / `validateOrder()`.
- **Platform metadata interfaces:** `PlatformInfoInterface` for exposing platform capabilities and metadata.
- **Webhook IP filtering:** `IpWhitelist` and `IpWhitelistInterface` for IP-based access control on webhooks with support for CIDR notation and multiple IP patterns.
- **Enhanced logging:** `CookieServiceModeChecker` for detecting and logging cookie-based service mode configuration.
- **Language configuration:** `LanguageProviderInterface` for pluggable language/localization settings.
- **Frontend environment builders:** 
  - `AbstractShopEnvironmentBuilder` for constructing shop environment metadata.
  - `PaywallConfigBuilder` for building paywall configuration with theme and capability resolution.
  - `CapabilityResolver` for resolving platform capabilities and feature flags.
  - `ThemeFamilyRules` for custom theme family and style mapping.
- **Shop domain builders:** `CartBuilderInterface`, `CustomerBuilderInterface`, `AbstractCartBuilder`, `AbstractCustomerBuilder`, and `AbstractStatusAdapter` for type-safe shop object construction.
- **Widget script helpers for the Comfino Web SDK:** `WidgetSdkInitScript` and `WidgetSdkInitScriptHelper` for integrations targeting `comfino-sdk.min.js` — initializes via `window.Comfino.ComfinoSDK.getInstance()`, `sdk.init()`, and `sdk.createWidget()`. Use `WidgetFrontendInitScriptHelper` instead for the legacy `ComfinoWidgetFrontend.init()` interface.
- Migration guide ([UPGRADE.md](UPGRADE.md)) for v2.0.0 breaking changes.
- Suggested dependency: `symfony/cache` for PSR-6 cache with tag support.

### Changed
- `AllowedProductsConfigBuilder::fromPersistedArray()` now drops entries whose `type` resolves to `UnknownLoanType` (was: kept the flyweight), filters `terms` to positive integers (was: any intval result), and drops entries with `minTerm > maxTerm` (was: kept).

### Improved
- Cache system now uses Symfony Cache Contracts (replaces abandoned `cache/tag-interop` dependency).
- Widget and frontend rendering now use context-aware escaping (JSON encoding for JavaScript, `htmlspecialchars` for HTML).
- Webhook validation enhanced with size limits, optional replay/rate-limit hooks, and IP-based access control via `IpWhitelist`.
- `WebhookManager` supports IP whitelist filtering for incoming webhook requests.
- `ApiClientFactory` constructor updated to accept optional `PlatformInfoInterface` for platform-aware client configuration.
- Logging improvements: `DebugLogger` and `ErrorLogger` now support cookie service mode detection and enhanced context.
- `WidgetFrontendInitScriptHelper` (formerly `WidgetInitScriptHelper`) refactored for improved theme and environment support.

## [1.0.0] - 2026-04-17

### Added
- Initial release of the Comfino Payment Gateway PHP Backend SDK.
- `ConfigurationManager` singleton for centralized API credentials and plugin settings storage via a pluggable `StorageAdapterInterface`.
- `CacheManager` singleton wrapping a PSR-6 `CacheItemPoolInterface` with optional PSR-6 cache-tag interop support.
- `DebugLogger` and `ErrorLogger` singletons backed by a PSR-3 `LoggerInterface`; `SensitiveDataProcessor` masks API keys and personal data before writing to logs.
- `LoggerFactory` for creating preconfigured logger instances.
- `ProductTypeFilterManager` with a composable filter chain: `FilterByProductType`, `FilterByCartValueLowerLimit`, `FilterByCartValueUpperLimit`, `FilterByExcludedCategory`.
- `CategoryTree` with lazy construction via `BuildStrategyInterface` and O(1) ID-indexed lookups; `CategoryFilter` for ancestor/descendant exclusion checks.
- `WebhookManager` for routing authenticated incoming webhook requests to registered `WebhookEndpointInterface` implementations with optional rate-limiting and replay-protection hooks.
- Built-in webhook endpoint implementations: `StatusNotification`, `Configuration`, `CacheInvalidate`.
- `ApiClientFactory`, `OrderFactory`, and `WebhookManagerFactory` for consistent object construction in e-commerce plugin integrations.
- `WidgetInitScript` and `WidgetInitScriptHelper` for building the Comfino widget initialization script tag.
- `FrontendHelper` with logo URL and paywall auth hash helpers for frontend rendering.
- Concrete shop domain model implementations: `Order`, `Cart`, `CartItem`, `Product`, `Customer`, `Address`, `LoanParameters`, `Seller`.
- `Cart::getItemsCount()` and `Cart::getTotalItemsCount()` for distinct item count and total quantity respectively.
- `StatusManager` singleton mapping platform-specific order status adapters to Comfino order statuses.
- `FileUtils` helper for safe file read/write operations within plugin contexts.
- `CacheItemType` enum for typed cache key namespacing.
- Depends on `comfino/php-api-client ^2.0` for all Comfino REST API communication.
- PSR-3 (`psr/log`) and PSR-6 (`psr/cache`) interfaces with no concrete implementations bundled — bring your own (e.g. `monolog/monolog`, `cache/filesystem-adapter`).
- Docker development environment (PHP 8.1-cli-alpine with optional Xdebug) and `bin/` wrapper scripts.
- PHPUnit 10.5 test suite (unit tests for all major subsystems).
- GitHub Actions CI matrix across PHP 8.1–8.4 with Codecov coverage upload.
- PHP_CodeSniffer PSR-12 enforcement and PHPStan level-6 static analysis.
- Comprehensive webhook security and signature verification documentation in `docs/webhooks.md`.

### Improved
- JSON processing in `WebhookEndpoint` and `WidgetInitScriptHelper` for more robust data handling.
- Security enhancements in webhook processing, logging, and frontend helper classes.
- Expanded unit test coverage for webhook management, frontend helpers, and widget initialization scripts.
- Composer configuration and project documentation updates.

[Unreleased]: https://github.com/comfino/php-sdk/compare/2.0.0...HEAD
[2.0.0]: https://github.com/comfino/php-sdk/compare/1.0.0...2.0.0
[1.0.0]: https://github.com/comfino/php-sdk/releases/tag/1.0.0
