# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.4.0] - 2026-10-29

Requires `comfino/php-api-client` 4.0, which adds `OrderInterface::getPromoCode()`; hosts that implement `OrderInterface`
themselves must add it. Hosts that use this package's `Order` are unaffected.

### Added

- **Remote flags and promotions.** `SettingsManager::getRemoteFlagAttributes()` fetches the shop's flags once and keeps the map exactly
  as the API sent it (`PROMO` included) in the tenant's cache for the lifetime the API names, at most 24 hours. A failed lookup
  returns an empty map, is remembered for 5 minutes and is not reported as an error. `getCheckoutFlagAttributes()`,
  `getRemoteFlagNames()` and `sanitizeRemoteFlagAttributes()` prepare the checkout payload. `CacheItemType::REMOTE_FLAGS`
  (`remote_flags`) lets a signed cache invalidation clear the map; without it such a request would have been ignored.
- **`PaywallConfigBuilder::buildConfig()` and `PaywallConfig` carry `flags` and `flagAttributes`** (both `null` when empty).
- **`ResponseBodySummarizer`.** An HTML error page in an API response (a framework debug page, a proxy error page) is reduced to its
  text, prefixed `[HTML response] `, and any response body is cut at 2000 characters. Used for the local log, error reports and
  `ApiErrorDetails`.
- **Customer-entered promotional code on orders.** `Order` takes an optional `$promoCode`, `OrderFactory::createOrder()`
  takes an optional trailing `promoCode`, and the new `PromoCode::normalize()` cleans the value (trim, strip markup,
  drop a blank or over-64-character code) so an unusable code never makes order creation fail. `PromoCode::REQUEST_FIELD`
  names the `comfino_promo_code` field hosts read from the checkout request. Needs `comfino/php-api-client` 4.0, whose
  `OrderInterface` declares `getPromoCode()`.

### Changed

- **Requires `comfino/php-api-client: ^4.0`.**
  In 4.0, `TooManyRequests` (HTTP 429) is no longer a `RequestValidationError`; a host catch block for refused request data
  no longer catches a 429. `ErrorLogger::isReportable()` and `resolveSeverity()` give the same results as before.
- **`SettingsManager` reads the `Comfino-Cache-TTL` header** (was `Cache-TTL`) for product types, widget types and creditors.

### Fixed

- **A refused cancellation (HTTP 409) is no longer treated as delivered.** `ApiTransientErrorClassifier` absorbs only 404 for
  `cancel_order`; a 409 means the order can no longer be cancelled, so it is dead-lettered and reported instead of silently dropped.

### Security

- **`SensitiveDataProcessor` redacts exceptions and the record message.** An exception in the log context becomes plain data with a
  redacted message, a capped redacted trace and its redacted `previous` chain; the message of the record itself is redacted too.

## [3.3.0] - 2026-10-19

### Added

- **Product tags and tag-based payment filtering.** `TaggedProductInterface` (implemented by `Product` through a new
  optional `$tags` constructor argument), `Cart::getCartTags()`, `TagFilter`, and the opt-in / block-list filters
  `FilterByRequiredTag`, `FilterByExcludedTag` and `FilterByRequiredCategory`. Tag matching is exact by default or
  case-insensitive with `caseInsensitive: true`.
- **Term limits per tag or category** (`Comfino\Backend\Payment\TermLimit`), applied through `FilterByTermLimits`.
- **Product type filters built from the configuration.** `SettingsManager::getAllowedProductTypesFiltered()` applies
  the registered filters plus those derived from the minimal cart amount and category filter settings. The category
  tree is supplied through the new `CategoryTreeProviderInterface`.
- **API error handling.** `ApiErrorProcessor` and `ApiErrorDetails` turn an API client exception into debug events and
  a structured error report.
- **Health recording** (`Backend\Health`). `ApiHealthRecorder`, `WebhookHealthRecorder` and `HealthSnapshot` for admin
  panels.
- **Circuit breaker, rate limiter and request observer** can be passed to `ApiClientFactory::createClient()` and
  `createClientFromPlatformInfo()`. Without them the client is built as before.
- **`ErrorLoggingTokenService`** claims and refreshes the error-logging access token.
- **`PluginUpdateChecker`** checks the release API for a newer plugin version, with result caching.
- **`PaywallConfigBuilder::buildCheckoutPayload()`** and `CheckoutPayloadExtras` build the checkout payment-method
  payload.
- **`trackingConsent` option** in `PaywallConfig` and `PaywallConfigBuilder::buildConfig()`.
- **Configuration validation.** `ConfigurationManager::setValueConstraints()` (with `HttpsUrlConstraint`,
  `IntRangeConstraint`, `AllowedValuesConstraint`) validates remote writes, and `setChangeObserver()` reports changes.
- **Status adapter hooks.** `AbstractStatusAdapter::acceptsOrder()` and `isStatusAlreadyApplied()`.
- **Loan selection validation.** `OrderValidator::validateLoanSelection()` and
  `SettingsManager::resolveAllowedProductTypes()`.
- **Queue purging.** `PurgeableRetryQueueStorageInterface` and a `$maxAgeSeconds` limit on queue processing; expired
  requests are reported as `RequestExpired`.
- **`TokenServiceModeChecker`**: service mode protected by a per-install token.
- **Error severity and reporting policy.** `ErrorLogger::resolveSeverity()` returns Warning for transient failures and
  request-declared idempotent ones and the caller's default for everything else; `ErrorLogger::isReportable()` is false
  for request validation and authorization errors. Hosts call `isReportable()`, `resolveSeverity()`, then `sendError()`.
- **`WebhookVerificationObserverInterface`** and `WebhookManager::getLastVerificationOutcome()` for diagnostics.

### Changed

- **`ext-mbstring` is now required.**
- **Requires `comfino/php-api-client: ^3.5`.**
- **`StatusManager::DEFAULT_FORBIDDEN_STATUSES` now includes `CANCELLED_BY_SHOP`**, so an inbound echo of the shop's own
  cancellation is no longer applied.
- **`StatusManager::setOrderStatus()` runs the adapter inside `StatusApplicationContext`**, so custom adapters no
  longer echo a webhook-applied status back to the API.
- **Queued `cancel_order` requests for orders that are already closed are skipped** instead of being sent.
- **`ConfigurationManager::updateConfigurationOptions()` and `setConfigurationValues()` return the rejected option
  names** instead of `void`.
- **`setConfigurationValues($options, [])` now allows no write** (previously it wrote everything). `null` still means
  "all options".
- **`ErrorLogger::logError()` accepts an optional PSR-3 `$context`.**
- **Webhook API keys must be at least 16 characters.** `TenantWebhookContext` (and `StaticApiKeyResolver`, which filters
  first) refuses a shorter key; a context left with no usable key is an unknown tenant. Custom resolvers must filter
  short keys themselves. `TenantWebhookContext::MIN_API_KEY_LENGTH` is public.
- **`ApiClientFactory` clamps timeouts and attempts.** Connection timeout at most 30 s and transfer timeout at most
  60 s (the connection timeout comes down further when needed to stay within a third of the transfer timeout), attempts
  1 to 5. Out-of-range values no longer throw or hold a request for minutes. The bounds are public constants.
- **The queue stores a redacted, capped `last_error`.** E-mail addresses, phone numbers, IP addresses and credentials are
  masked and the line is cut to 512 bytes (`OutboundRequestQueue::MAX_LAST_ERROR_LENGTH`), for every storage.
- **`ApiTransientErrorClassifier` honors the request-declared idempotent flag** (`isIdempotentFailure()` from
  `php-api-client` 3.5): such a failure is treated as success for any operation type; the operation list stays as the
  fallback.

### Fixed

- **`WebhookManager` rejections now include the message in the response body**, not only in the reason phrase.
- **Minimal cart amount conversion rounds** instead of truncating (12.34 gives 1234 cents, not 1233).

### Security

- **`ErrorLogger` redacts the error message and stack trace** (e-mail addresses, phone numbers, IP addresses,
  credentials), in one step shared by the local log, the queued report and the direct send.
- **`SensitiveDataProcessor` also masks `cr_signature` and `x_cr_signature`**, the spelling WordPress gives the header.
- **Removed the webhook signing oracle.** `WebhookManager::getCalculatedCrSignature()` now always returns `null`
  (deprecated, removal in 4.0).
- **Authorization failures return one generic response** (`{"error":"Unauthorized."}` with 401 or 403), so the response
  no longer reveals which check failed.
- **`ErrorLogger` redacts request and response bodies** before logging or reporting them. Use
  `setVerboseLocalLog(false)` to keep bodies out of the local log.
- **`SensitiveDataProcessor` supports Monolog 3** and redacts personal data fields and signature headers.
- **`Configuration` webhook endpoint hardened.** `server_addr` is always `null`, and remote `debug_log` reads can be
  disabled and return masked values.

## [3.2.1] - 2026-09-10

### Fixed

- `HostedConnectorInfo::getVersion()` no longer reports the connector's own version as the merchant platform's.
- `UserAgentBuilder` omits the platform segment when the platform version is unknown.

## [3.2.0] - 2026-09-08

### Added

- `TenantAwareRetryableOperationHandlerInterface`: a queue handler that receives the tenant key of the request it
  delivers.
- `OutboundRequestQueueFactory::create()` accepts an optional `$tenantClientFactory`.

### Fixed

- Queued error reports are now delivered with the credentials of the merchant that produced them, not the default
  merchant's.

## [3.1.0] - 2026-08-25

Backward compatible. Requires `comfino/php-api-client: ^3.1`.

### Added

- `TenantAwareWebhookEndpointInterface` and `StatusAdapterResolverInterface`, so one `WebhookManager` can serve several
  merchants.
- `ReplayKeyExtractorInterface` with `SignatureReplayKeyExtractor` (default) and `HeaderReplayKeyExtractor`.
- `IpWhitelist` supports CIDR ranges and a report-only mode; sandbox server IPs are available through
  `IpWhitelist::forComfino(includeSandbox: true)`.
- `Psr6CircuitBreakerStore` and `Psr6TokenBucketStore` for sharing resilience state across workers.
- `StatusManager::create()`, plus `scopes()` and `scopeCount()` on all scope-addressed classes.
- `WebhookManager` accepts `$ipResolver`, `$replayKeyExtractor` and `$maxBodyBytes`; `VerifiedWebhookRequest` exposes
  `$clientIp` and `$replayKey`.

### Fixed

- The webhook rate limit is consumed once per request instead of once per endpoint tried.
- The IP allow-list and the rate limiter now use the same client address.
- The 1 MB body limit now also applies to PSR-7 requests.

## [3.0.0] - 2026-08-21

Multi-tenant release: the SDK can now serve several merchants from one long-lived process. Single-shop plugins keep
working, because the tenant scope is an optional trailing argument.

### Breaking Changes

- Requires `comfino/php-api-client: ^3.0`.
- `RetryQueueStorageInterface` changed: `peekBatch()` and `count()` take optional tenant arguments, and
  `pendingTenantKeys()` is new. Platform storage adapters must be updated.
- `QueueErrorDisposition` has a new `PauseTenant` case, returned for HTTP 401/403. Custom classifiers must handle it.
- `ConfigurationManager::__destruct()` no longer persists changes. Call `flush()`, or `setPersistOnDestruct(true)` to
  keep the old behavior.
- `WebhookManager` verifies against one tenant's keys. The fourth constructor argument accepts a
  `WebhookTenantResolverInterface` or `string[]`, and `verifyRequest()` returns a `VerifiedWebhookRequest`.

### Added

- Per-scope instances (`getInstance(..., string $scope = '')` and `reset(?string $scope = null)`) for `ErrorLogger`,
  `DebugLogger`, `SettingsManager`, `ProductTypeFilterManager` and `StatusManager`.
- `ScopedCache` and `CacheManager::forScope()`.
- `StatusApplicationContext` is per scope.
- `ErrorLogger::registerGlobalHandlers()`, `unregisterGlobalHandlers()` and `withGlobalHandlers()`.
- `ConnectorInfoInterface`, `HostedConnectorInfo` and `UserAgentBuilder` for connectors that run outside the shop.
- `ApiClientFactory::createSharedClient()` and a `maxTotalTransferTimeout` option.
- Tenant-aware rate limiting and replay protection (`TenantAwareRateLimiterInterface`,
  `TenantAwareReplayProtectionInterface`); `WebhookManager` now answers rate-limited requests with a correct 429 and
  `Retry-After`.
- `WebhookTenantResolverInterface`, `TenantWebhookContext`, `StaticApiKeyResolver` and `VerifiedWebhookRequest`.
- Per-tenant queue fairness, backoff and pausing; `TenantPauseReporterInterface`.
- Negative caching of failed API lookups in `SettingsManager`.

### Fixed

- `WebhookManager` ignores empty and `null` API keys instead of accepting forged signatures or throwing a `TypeError`.

### Deprecated

- `RateLimiterInterface` and `ReplayProtectionInterface`: use the tenant-aware versions.
- `CacheManager::get()` / `set()`: use `CacheManager::forScope()`.
- `ErrorLogger::initHandlers()`: use `registerGlobalHandlers()`.
- `StatusApplicationContext::run()`: use `StatusApplicationContext::forScope($scope)->apply()`.
- `WebhookManager::getReceivedCrSignature()` / `getCalculatedCrSignature()`: read `VerifiedWebhookRequest` instead.

## [2.0.0] - 2026-07-22

See [UPGRADE.md](UPGRADE.md) for the migration guide.

### Breaking Changes

- `ErrorLogger::sendError()` takes typed `ErrorCategory`, `ErrorSeverity` and `OperationContext` arguments instead of
  an `$errorPrefix` string. `sendErrorWithContext()` and `sendErrorAuto()` were removed.
- `DebugLogger::logEvent()` / `logEventConditional()` take an optional trailing `?string $eventType` instead of
  `$eventPrefix`. The `*WithContext()` variants were removed.
- `CacheInvalidate` requires a `Psr\Cache\CacheItemPoolInterface`. Tag-based invalidation needs a pool that also
  implements `Symfony\Contracts\Cache\TagAwareCacheInterface`.
- `WidgetInitScript` is now `WidgetFrontendInitScript`, and `WidgetInitScriptHelper` is now
  `WidgetFrontendInitScriptHelper`.
- `WidgetSdkInitScriptHelper` placeholders changed: `ENVIRONMENT` and `HAS_PRICE_INPUT` added;
  `WIDGET_PRICE_SELECTOR`, `WIDGET_PRICE_OBSERVER_SELECTOR`, `WIDGET_PRICE_OBSERVER_LEVEL` and `EMBED_METHOD` removed;
  `PRODUCT_PRICE` is an integer in cents.
- `SettingsManager::getInstance()` has a new `?ConfigurationManager $configurationManager` argument in sixth position.
- `OrderInterface::getAllowedProductsConfig(): ?array` was added. Return `null` if not needed.
- `PaywallConfigBuilder::buildConfig()` requires a new `string $accessToken` argument after `$apiKey`; the API key is no
  longer sent to the frontend.

### Security

- Fixed XSS in widget initialization and logo rendering.
- `WebhookManager` supports replay protection and rate limiting, and rejects bodies over 1 MB.
- Stronger tokens (128-bit entropy, `random_bytes()`, SHA-256 checksums).

### Added

- Structured error reporting (plugin and platform versions, category, severity, context) with
  `ErrorMessageNormalizer`.
- Frontend error-reporting tokens in `PaywallConfig` (`loggingToken`, `trackId`).
- Outbound request queue (`Comfino\Backend\Queue`) that retries idempotent API calls and keeps error reporting from
  blocking shop requests.
- `StatusApplicationContext`, which lets platforms avoid echoing API-initiated status changes back to the API.
- Creditors and term limits: `SettingsManager::getCreditors()`, `getAllowedProductsConfig()`,
  `AllowedProductsConfigBuilder` and `AllowedProductsConfigValidator`.
- `OrderValidator` for pre-submission order checks.
- `WidgetSdkInitScript` and `WidgetSdkInitScriptHelper` for the ComfinoPay Web SDK.
- `SdkUrlBuilder`, `ProductWidgetScriptHelper`, `PaywallCartSerializer` and `FrontendHelper` URL helpers.
- `PaywallConfigBuilder`, `CapabilityResolver`, `ThemeFamilyRules` and `AbstractShopEnvironmentBuilder`.
- `AbstractCartBuilder`, `AbstractCustomerBuilder` and `AbstractStatusAdapter`.
- `IpWhitelist` for webhook IP filtering.
- `PlatformInfoInterface`, `LanguageProviderInterface` and `CookieServiceModeChecker`.
- Optional tenant scope for `ConfigurationManager` and `CacheManager`.

### Changed

- `psr/log` and `psr/cache` requirements widened for older stacks.
- `AbstractStatusAdapter::setStatus()` runs inside `StatusApplicationContext`.
- `AllowedProductsConfigBuilder::fromPersistedArray()` drops unknown types and invalid term entries.
- `CapabilityResolver` recognizes the `blocks` theme family.

## [1.0.0] - 2026-04-17

### Added

- Initial release of the ComfinoPay PHP backend SDK: configuration, cache and logging services, payment product
  filters, category tree, webhook handling with signature verification, factories, frontend helpers and concrete shop
  domain classes.
- PSR-3 and PSR-6 interfaces only; bring your own implementations.

[Unreleased]: https://github.com/comfino/php-sdk/compare/3.4.0...HEAD
[3.4.0]: https://github.com/comfino/php-sdk/compare/3.3.0...3.4.0
[3.3.0]: https://github.com/comfino/php-sdk/compare/3.2.1...3.3.0
[3.2.1]: https://github.com/comfino/php-sdk/compare/3.2.0...3.2.1
[3.2.0]: https://github.com/comfino/php-sdk/compare/3.1.0...3.2.0
[3.1.0]: https://github.com/comfino/php-sdk/compare/3.0.0...3.1.0
[3.0.0]: https://github.com/comfino/php-sdk/compare/2.0.0...3.0.0
[2.0.0]: https://github.com/comfino/php-sdk/compare/1.0.0...2.0.0
[1.0.0]: https://github.com/comfino/php-sdk/releases/tag/1.0.0
