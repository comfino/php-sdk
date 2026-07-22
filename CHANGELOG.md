# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-07-22

### Breaking Changes
- **`ErrorLogger::sendError()` signature changed:** the free-form `$errorPrefix` string argument is replaced by three
  leading typed arguments — `ErrorCategory $category`, `ErrorSeverity $severity`, `OperationContext $context` (from
  `Comfino\Api\Dto\Plugin`). `sendErrorWithContext()` and `sendErrorAuto()` are removed; `sendError()` now always
  auto-captures the caller location into `environment['caller']`. `errorHandler()` / `exceptionHandler()` are updated
  accordingly and now report the PHP error-level name / exception class short name as `errorCode` instead of `"0"`.
- **`DebugLogger::logEvent()` / `logEventConditional()` signature changed:** the leading free-form `$eventPrefix`
  string is replaced by an optional trailing `?string $eventType` parameter; caller location is now always
  auto-captured into `$parameters['_caller']`. `logEventWithContext()` and `logEventConditionalWithContext()` are
  removed — both methods now always auto-capture context.
- **Cache system refactoring:** `CacheInvalidate` constructor now requires `Psr\Cache\CacheItemPoolInterface` instead of `Cache\TagInterop\TaggableCacheItemPoolInterface` (removed dependency on abandoned `cache/tag-interop` package).
  - For tag-based cache invalidation, the injected pool must also implement `Symfony\Contracts\Cache\TagAwareCacheInterface` (e.g., Symfony's `TagAwareAdapter`).
  - If the pool does not support tagging, `CacheInvalidate` silently skips the invalidation step.
  - See [UPGRADE.md](UPGRADE.md) for migration guide.
- Bumped minimum `psr/cache` from `^2.0 || ^3.0` to `^1.0 || ^2.0 || ^3.0` for broader compatibility with legacy stacks.
- **Widget script class renames:** `WidgetInitScript` renamed to `WidgetFrontendInitScript`; `WidgetInitScriptHelper` renamed to `WidgetFrontendInitScriptHelper`. Update all class references and constructor calls in your integration.
- **`WidgetSdkInitScriptHelper` parameter changes** (vs. the former `WidgetInitScriptHelper` when targeting `comfino-sdk.min.js`): removed `WIDGET_PRICE_SELECTOR`, `WIDGET_PRICE_OBSERVER_SELECTOR`, `WIDGET_PRICE_OBSERVER_LEVEL`, and `EMBED_METHOD`; added `ENVIRONMENT` (`'sandbox'|'production'`) and `HAS_PRICE_INPUT` (bool); `PRODUCT_PRICE` is now an integer in grosze (smallest currency unit) instead of a PLN float.
- **`SettingsManager::getInstance()` signature change:** a new `?ConfigurationManager $configurationManager` parameter has been inserted as the 6th argument (between `?PlatformInfoInterface` and `string $apiKey`). All existing call sites must pass `null` or a configured `ConfigurationManager` instance in that position.
- **`OrderInterface::getAllowedProductsConfig(): ?array` added:** third-party implementations of `OrderInterface` must now implement this method. The safe default is to return `null`.
- **`PaywallConfigBuilder::buildConfig()` signature changed:** a new required `string $accessToken` parameter is
  inserted right after `$apiKey`. The frontend logging token is now HMAC-signed with this plugin access token (from
  `POST /v1/error-logging-token`) instead of the raw Comfino API key, so the API key itself never reaches the
  frontend error-reporting service. Callers must fetch and pass the access token.

### Security Fixes
- **XSS vulnerabilities in widget initialization and logo rendering** — Fixed unescaped JavaScript and HTML injection in `WidgetInitScriptHelper` and `FrontendHelper`. Template values are now properly JSON-encoded and HTML-escaped.
- **Webhook request validation** — Added optional replay attack mitigation and rate-limiting hooks to `WebhookManager`. Introduced a 1 MB request body size limit to prevent memory exhaustion.
- **Cryptographic improvements** — Increased token entropy from 80 to 128 bits, replaced `uniqid()` with `random_bytes()` for secure random IDs, upgraded MD5 checksums to SHA-256.

### Added
- **`ErrorMessageNormalizer`** (`Comfino\Backend\Log`): strips dynamic, dedup-unfriendly parts from error messages and
  stack traces before transmission — communication-error timestamps, timeout/byte-count values, PHP "called in ... on
  line N" suffixes, and shop-specific filesystem prefixes (keeping the plugin-relative path). `ErrorLogger` applies it
  automatically in `sendError()`.
- **Structured `ShopPluginError` reporting:** `ErrorLogger` now sends `pluginVersion`, `platformVersion`,
  `phpVersion`, `occurredAt` (via an injectable `ClockInterface`), and the typed `category`/`severity`/`context`
  fields to the Comfino API, matching the ecommerce API's structured `ShopPluginError` format
  (`Comfino-Message-Version` header). Requires `comfino/php-api-client` with the corresponding `ErrorCategory`,
  `ErrorSeverity`, `OperationContext` enums and extended `ShopPluginError` DTO.
- **Frontend error-reporting tokens:** `PaywallConfig` gains two new optional properties — `loggingToken` and `trackId` — included in `getAsArray()` and forwarded to the Comfino Web SDK so browser-side errors can be correlated with backend API calls. `PaywallConfigBuilder::buildConfig()` accepts an optional `$trackId` and auto-generates the logging token.
- **`LOGGING_TOKEN` / `TRACK_ID` placeholders** added to `WidgetSdkInitScriptHelper` and wired into `WidgetSdkInitScript`'s `sdk.init()` call. The SDK `<script>` element now also sets `crossOrigin = 'anonymous'` for CORS-compliant error reporting.
- **`StatusApplicationContext`** (`Comfino\Shop\Order`): tracks whether an API-initiated order status change is currently being applied, so platform integrations can suppress reflexive outbound calls back to the Comfino API from their order-persistence event hooks (e.g., avoid echoing a cancellation request for an order the API itself just reported as canceled). Exposes `run(callable)` and `isActive()`.
- **Outbound request queue** (`Comfino\Backend\Queue`): a platform-agnostic, durable FIFO retry queue for idempotent fire-and-forget API calls. `OutboundRequestQueue::submit()` attempts a call once with minimal timeouts and, on transient failure (timeout / network / 5xx / 429), persists it for later resend instead of blocking the shop request thread; `process()` drains pending requests one by one in insertion order, stopping at the first transient failure. Permanent 4xx errors are dropped; 404/409 on `cancel_order` are treated as already-done. Persistence is delegated to a platform-implemented `RetryQueueStorageInterface`; per-operation handling is done via `RetryableOperationHandlerInterface` handlers (`CancelOrderHandler` for `cancel_order` and `ReportErrorHandler` for `report_error`). `ErrorLogger` integrates with the queue when an `OutboundRequestQueue` is injected: error reports are always enqueued as pure fire-and-forget via `ReportErrorHandler` rather than attempted inline, so logging never blocks a shop request thread. Includes `OutboundRequestQueueProcessor` for cron/opportunistic draining, a `DeadLetterReporterInterface` hook for give-up notification, `OutboundRequestQueueFactory`, and a `ClockInterface` / `SystemClock` time abstraction.
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
- **`SdkUrlBuilder`** (`Comfino\Frontend`): cross-platform static helper that centralizes CDN URL construction for the Comfino web SDK. The web SDK is a single, ESM-only bundle served from the primary `sdk.comfino.pl` host (sandbox `sdk.craty.pl`; the legacy `widget.*/sdk/v<n>/…` alias keeps serving via the edge rewrite). `getSdkScriptUrl(bool $sandboxMode, bool $devOverridesEnabled, int $version)` returns the `comfino-sdk.min.js` URL for production or sandbox (always loaded via `<script type="module">`); `getApiHostOverride()` returns a validated dev-environment API host override when active. Dev overrides (`COMFINO_DEV_SDK_SCRIPT_URL`, `COMFINO_DEV_API_HOST`) require both the server-set `COMFINO_DEV_ENV=TRUE` environment variable and the per-shop opt-in flag, and are validated through `UrlValidator`'s allow-list to prevent redirect to attacker-controlled hosts.
- **`FrontendHelper::getCheckoutLoaderStylesheetUrl(string $sdkScriptUrl): ?string`**: derives the URL of the shared CDN-served checkout stylesheet (`comfino-checkout.css`) from the SDK script URL's origin, so shop plugins do not need to hardcode the CDN host. Returns `null` when the input URL is empty or malformed.
- **Frontend environment builders:** 
  - `AbstractShopEnvironmentBuilder` for constructing shop environment metadata.
  - `PaywallConfigBuilder` for building paywall configuration with theme and capability resolution.
  - `CapabilityResolver` for resolving platform capabilities and feature flags.
  - `ThemeFamilyRules` for custom theme family and style mapping.
- **Shop domain builders:** `CartBuilderInterface`, `CustomerBuilderInterface`, `AbstractCartBuilder`, `AbstractCustomerBuilder`, and `AbstractStatusAdapter` for type-safe shop object construction.
- **Widget script helpers for the Comfino Web SDK:** `WidgetSdkInitScript` and `WidgetSdkInitScriptHelper` for integrations targeting `comfino-sdk.min.js` — initializes via `window.Comfino.ComfinoSDK.getInstance()`, `sdk.init()`, and `sdk.createWidget()`. Use `WidgetFrontendInitScriptHelper` instead for the legacy `ComfinoWidgetFrontend.init()` interface.
- Migration guide ([UPGRADE.md](UPGRADE.md)) for v2.0.0 breaking changes.
- Suggested dependency: `symfony/cache` for PSR-6 cache with tag support.
- **`OrderValidator`** (`Comfino\Shop\Order`): platform-agnostic pre-submission validation for an `OrderInterface` —
  customer e-mail/phone/name, delivery address (city, postal code), and non-empty/positive cart totals. `validate()`
  returns a list of stable failure keys (constants on the class) rather than translated sentences, so each plugin can
  map them to its own customer-facing messages.
- **`AllowedProductsConfigValidator`** (`Comfino\Backend\Payment`): validates and canonicalizes the raw
  "installment term limits" JSON configuration each plugin persists (JSON syntax, top-level array shape, known
  product type, positive `minTerm`/`maxTerm`/`terms` integers, `minTerm <= maxTerm`, term dedup) and normalizes it
  back to canonical JSON. Failures are reported as stable keys plus ordered interpolation params so each plugin can
  render its own translated message. Complements `AllowedProductsConfigBuilder`.
- **`PaywallCartSerializer`** (`Comfino\Frontend`): serializes a `Cart` DTO into the flat array shape the paywall
  iframe consumes for `COMFINO_CART_UPDATE` messages (products list plus cart-level delivery fields), defaulting null
  numeric fields to `0` and category to `''` as the iframe expects concrete values rather than nulls.
- **Product-page widget support:**
  - **`ProductWidgetScriptHelper`** (`Comfino\Frontend`): renders the default product-page widget initialization — a
    `<script type="application/json" id="comfino-widget-config">` config block followed by the CDN-hosted product
    widget `<script src>` (`getScriptUrl()`, `buildConfig()`, `renderScript()`). Supports per-platform bundles
    (`comfino-<platform>-widget.min.js`) alongside the generic default, mirroring how the checkout paywall glue is
    already wired. Config values are filtered to the published `WidgetConfig` contract and JSON-encoded with the same
    defensive flags used elsewhere in the SDK to prevent script-tag breakout / entity smuggling.
  - **`FrontendHelper::getProductWidgetScriptUrl(string $sdkScriptUrl, string $platform): ?string`**: derives a
    platform's CDN-served product widget bundle URL from the SDK script URL's origin, the product-page sibling of
    `getCheckoutScriptUrl()`. Returns `null` for an empty/malformed SDK URL or a platform slug outside `[a-z0-9-]+`.
- **`AbstractShopEnvironmentBuilder::buildReportArray()`**: builds the full backend shop-environment report as a
  snake_case associative array (the same shape posted to `log-shop-environment`), for exposing the report on demand
  via the plugin configuration/webhook endpoint.
- **On-demand shop-environment report in the config webhook:** `Backend\Webhook\Endpoint\Configuration` accepts an
  optional trailing `?\Closure $shopEnvironmentReportProvider` constructor parameter; when set, its return value is
  included as `shop_environment` in the endpoint's configuration response, giving the Comfino API selector knowledge
  base on-demand access to the full environment report.
- **Multi-tenant scoping for singletons:** `ConfigurationManager::getInstance()` and `CacheManager::init()` accept a
  new optional trailing `string $scope` parameter, keeping one instance / cache-key namespace per tenant (e.g.
  store/website id) so a single process serving more than one shop/tenant no longer risks one tenant's in-memory or
  cached values leaking into another's request. `ConfigurationManager::reset(?string $scope = null)` can now drop a
  single scope instead of all of them. Both default to the empty scope, preserving prior single-tenant behavior for
  existing callers.

### Changed
- Widened `psr/log` requirement from `^3.0` to `^1.1 || ^2.0 || ^3.0` for broader compatibility with legacy stacks.
- **`AbstractStatusAdapter::setStatus()`** now wraps the platform-specific `applyStatus()` call in `StatusApplicationContext::run()`. Integrations that react to order saves can query `StatusApplicationContext::isActive()` instead of hand-rolling their own suppression flag. Behavior is unchanged for integrations that do not consult the context.
- `AllowedProductsConfigBuilder::fromPersistedArray()` now drops entries whose `type` resolves to `UnknownLoanType` (was: kept the flyweight), filters `terms` to positive integers (was: any intval result), and drops entries with `minTerm > maxTerm` (was: kept).
- **`CapabilityResolver::resolveCapabilities()`**: the `'blocks'` theme family (WordPress/WooCommerce block themes)
  is now recognized and mapped to the same capability set as `'classic'` / `'storefront'` (no knockout/alpine/
  tailwind/requirejs/jQuery).

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
