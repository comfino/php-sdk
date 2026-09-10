# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [3.2.1] - 2026-09-10

### Changed

- **`UserAgentBuilder` omits the platform name/version segment when `ConnectorInfoInterface::getVersion()` is
  `null` or `''`**, instead of rendering it as `<name> [unknown]`. `HostedConnectorInfo::getVersion()` now returns
  its `$platformVersion` directly rather than falling back to the connector's own version, so an out-of-process
  connector that genuinely does not know its merchant's platform version produces `PlatformName Comfino [1.4.2], PHP
  [8.3.1], shop.example.com` instead of repeating its own version in a slot meant for the platform's. `PHP` and the
  domain are unaffected: those are still rendered as `unknown` when absent, since every deployment has one, even
  if it cannot be read.

### Fixed

- **`HostedConnectorInfo::getVersion()` no longer repeats the connector's own version as if it were the merchant
  platform's.** A connector that later passes `$platformVersion` explicitly, once it learns it, still renders that
  segment as before.

## [3.2.0] - 2026-09-08

### Added

- **`TenantAwareRetryableOperationHandlerInterface`** (`Comfino\Backend\Queue`): a queue handler that is told which
  merchant the request it is delivering belongs to. `OutboundRequestQueue` calls `executeForTenant($payload,
  $tenantKey)` on both `submit()` and `process()` whenever a handler implements the interface, passing the tenant key
  recorded on the queued request, and falls back to `execute($payload)` otherwise — so existing single-tenant handlers
  are unaffected.
- **`OutboundRequestQueueFactory::create()` gained a trailing `$tenantClientFactory` parameter** — a
  `Closure(?string $tenantKey): ClientInterface` used to build the client `ReportErrorHandler` delivers with. A
  multi-tenant host should pass it. `cancel_order` deliberately keeps the ambient `$minimalTimeoutClient`: its payload
  is platform-shaped, so hosts register their own tenant-aware handler for that operation.

### Fixed

- **Queued error reports were delivered with the wrong merchant's API key.** `ReportErrorHandler` held a single
  `ClientInterface`, so on the drain path — which runs under the platform's cron scope, not the scope that enqueued the
  work — every merchant's report was sent with the *default* merchant's credentials. The API answered 401, the
  classifier read that as permanent, and the report was dropped: the queue lost exactly the traffic it exists to
  protect, and only for the tenants that were not the default one. This is the last instance of the root cause that
  silently lost `cancel_order` deliveries in Magento 4.0.0/4.0.1 (fixed platform-side there) — and unlike
  `cancel_order`, a platform could not work around it locally, because `ErrorLogger` builds the payload.
  `ReportErrorHandler` now implements `TenantAwareRetryableOperationHandlerInterface` and accepts a
  `Closure(?string): ClientInterface` in place of a client, resolving the merchant's credentials at delivery time from
  the tenant key `ErrorLogger` already records. Passing a plain `ClientInterface` keeps the previous behavior, which is
  the correct one for a single-shop install; a factory that cannot resolve a tenant should throw, and the queue retries
  it as transient rather than losing the report to a drain that raced a configuration edit.

## [3.1.0] - 2026-08-25

3.0.0 made the SDK safe to share between merchants. Using it then surfaced eight things it had not finished, and this
release is those eight. Six are additive seams, two are defects. Nothing here changes the behavior for a plugin installed
in a shop: every new parameter is optional and trailing, every new interface is opt-in, and the whole suite that passed
against 3.0.0 passes unchanged.

### Breaking Changes

None. `comfino/php-api-client` is now required at `^3.1` (was `^3.0`) for the two atomic store interfaces this
release needs; that release is itself additive.

### Fixed

- **A request was charged one rate-limit token per endpoint *tried*, not per request.** The limiter was
  consumed inside `processRequest()`'s endpoint loop, so a manager with three registered endpoints charged up to three
  tokens for one request — against the names of the endpoints that did *not* handle it. The counters were therefore
  wrong and misattributed, and the effective quota depended on registration order. The request is now routed first and
  charged once, for the endpoint that will handle it. Invisible to a plugin registering one endpoint, which is why it
  needed to be filed rather than noticed.
- **The IP allow-list and the rate limiter disagreed about who called.** `IpWhitelist` resolved the caller
  through `ClientIpResolver`, honoring `CF-Connecting-IP` / `X-Forwarded-For` / `X-Real-IP`; the rate-limit key read
  `REMOTE_ADDR` raw. Behind a proxy — the deployment both features exist for — the allow-list saw the caller and the
  limiter saw the proxy, so every request arriving through one load balancer shared a single bucket and
  `RateLimitKey::$clientIdentifier` identified neither the sender nor the merchant. The manager now resolves the
  address once, through an injectable resolver, and uses it for the limiter and for `VerifiedWebhookRequest::$clientIp`.
- **The 1 MB body cap applied only to requests read from PHP globals.** Every framework integration passes its
  own PSR-7 request, and that path had no cap at all — a documented guarantee that most integrations never received.
  The cap now applies to both paths and is configurable per manager.

### Added

- **`TenantAwareWebhookEndpointInterface`** — an endpoint that implements it receives the
  `VerifiedWebhookRequest`, so it can act for the merchant the request was *verified* for instead of being constructed
  for one merchant. This is the webhook counterpart of 3.0.0's `ApiContext`, and it is what lets a multi-tenant host
  register **one** manager as a shared service: previously the manager, its tenant resolver and its endpoints all had
  to be rebuilt per request, and the resolver had to be *bound* to a merchant and re-check the request against it,
  because a resolver that honestly answered "whichever merchant the URL names" would let a notification verified for
  merchant B be applied by merchant A's endpoint. A second interface rather than a third parameter on
  `WebhookEndpointInterface`, because PHP forbids an implementation from declaring fewer parameters than its interface —
  so adding even an optional one would break every endpoint written so far. The two collapse in the next major.
- **`StatusAdapterResolverInterface`, accepted by `StatusNotification` in place of a `StatusManager`.** The
  endpoint then asks for the adapter of the merchant each request was verified for. Constructed with a `StatusManager`
  it behaves exactly as before. A tenant with no adapter — deprovisioned since the request was signed — is
  acknowledged rather than refused, because the sender retries every non-2xx; the payload is still validated, so a
  malformed notification is still a `400`.
- **`ReplayKeyExtractorInterface`, with `SignatureReplayKeyExtractor` (the default) and `HeaderReplayKeyExtractor`.**
  Replay protection used to key on the `CR-Signature`, which is `sha3-256(apiKey . rawBody)` — a function of
  the *content*, not of the delivery. For an order-status notification, whose body is essentially
  `{externalId, status}`, two genuine deliveries hash identically, so enabling replay protection silently converted
  "the merchant was told twice" into "the second real event was dropped". That is why a host without a per-delivery
  identifier should run with it off. The key is now an extraction step: the default preserves 3.0 behavior exactly,
  and a host whose deliveries carry an identifier keys on that instead. A null key means "cannot be identified, do
  not deduplicate" — failing open, which is why wiring `HeaderReplayKeyExtractor` before the API sends such a
  header is harmless. **The real fix is
  API-side**: a per-delivery id (a `CR-Delivery-Id` header would do) would make replay protection correct for every
  endpoint, and is worth raising with the API owners.
- **CIDR ranges, the sandbox addresses, and a report-only mode in `IpWhitelist`.** The list was an exact
  `in_array()`, so a host needing to allow a network wrote its own `IpWhitelistInterface`; entries may now be ranges.
  `IpUtils::COMFINO_SANDBOX_SERVER_IPS` and `IpWhitelist::forComfino(includeSandbox: true)` close the gap that made a
  production-only list reject every notification a merchant's *test* payments generate — the failure an allow-list
  exists to prevent, with the sign reversed. And `enforce: false` plus a PSR-3 logger allows the request while naming
  the address that arrived, because an allow-list is a hypothesis about who calls you and its failure mode is silent:
  run it in observation, read the log, then enforce.
- **`Psr6CircuitBreakerStore` and `Psr6TokenBucketStore`**, plus `Psr6StoreKey`. The api-client's breaker and
  outbound limiter shipped with process-local stores, so each worker learned independently that ComfinoPay was down and
  each worker got its own full token bucket — most of the value of both features gone, and the host left to write the
  store. These are that store, over any PSR-6 pool, with the state kept as plain arrays so a library upgrade cannot
  make existing rows unreadable. **Shared, and honest about not being exact:** PSR-6 has no compare-and-swap, which is
  a bounded cost for the breaker and a real one for the limiter — see their docblocks and
  `TokenBucketRateLimiter::isExact()`. Keys are digest-suffixed, because sanitizing `|` and `/` to `_` alone maps two
  different tenants' keys onto one cache row.
- **`StatusManager::create()`** — builds an instance without touching the scope cache, for the host that
  already holds the adapter for the request it is serving. `getInstance()` keeps the *first* adapter given for a scope,
  ignores every later one and never evicts, so an adapter carrying request-scoped collaborators serves the next request
  for that merchant and the map grows by one pinned object graph per merchant. That is now stated in the docblock
  rather than discoverable by reading `??=`.
- **`scopes()` and `scopeCount()` on every scope-addressed class** — `StatusManager`, `ConfigurationManager`,
  `SettingsManager`, `ProductTypeFilterManager`, `DebugLogger`, `ErrorLogger`. A host can now assert in a test that it
  releases what it registers, instead of discovering a forgotten `reset()` as a slow leak.
- **`IpWhitelist::isEnforcing()`** and `IpUtils::isComfinoSandboxServerIp()`.
- **`WebhookManager::DEFAULT_MAX_BODY_BYTES`**, and three trailing optional constructor arguments — `$ipResolver`,
  `$replayKeyExtractor`, `$maxBodyBytes` — forwarded by `WebhookManagerFactory::createWebhookManager()`.
- **`VerifiedWebhookRequest::$clientIp` and `$replayKey`**, so an endpoint and a log line name the same caller and the
  same delivery the guards did.

## [3.0.0] - 2026-08-21

The multitenant release. Both libraries were designed for the single-tenant plugin shape — one PHP process serves one
shop, holds one API key, reads one configuration table, and dies at the end of the request. Every design decision that
follows from that assumption becomes a correctness hazard in a long-lived, multi-tenant host, where one worker process
serves many merchants in sequence. This release removes those hazards from the SDK; `comfino/php-api-client` 3.0.0 did
the same on the protocol side, and is now the minimum requirement.

Every change below keeps the existing single-shop plugins working: the tenant scope is a trailing optional argument
that defaults to the previous global behavior, the legacy webhook interfaces are adapted rather than dropped, and the
one behavioral break (the `ConfigurationManager` destructor) has an explicit opt-out.

### Breaking Changes

- **Requires `comfino/php-api-client: ^3.0`** (was `^2.1`). The api-client removed `getOrder()` — order status is
  reconciled through the status webhook instead of polled — and replaced `Psr18ErrorDetector` with `ErrorClassifier`
  and `TimeoutAwareClientInterface` with `TimeoutConfigurableClientInterface`. See that package's changelog.
- **`RetryQueueStorageInterface` gained parameters and a method.** `peekBatch(int $limit, ?string $tenantKey = null,
  ?int $dueAt = null)`, `count(?string $tenantKey = null)`, and the new `pendingTenantKeys(?int $dueAt = null)`.
  Platform storage adapters must be updated; a single-shop adapter can ignore the tenant argument (it is always null
  for it) but must accept it. Honoring `$dueAt` is a SHOULD — an adapter that ignores it stays correct, because the
  queue re-checks, at the cost of a wasted batch slot.
- **`QueueErrorDisposition` gained a case, `PauseTenant`**, and `ApiTransientErrorClassifier` now returns it for
  HTTP 401/403 instead of `DropPermanent`. Custom classifiers and any `match` over the enum must handle it. A refused
  credential is a configuration event, not a bad request: the payload is fine, every request for that merchant fails
  identically until the key is fixed, and dropping them one at a time silently discarded a merchant's entire
  payment-relevant outbound stream while the queue reported itself healthy.
- **`ConfigurationManager::__destruct()` no longer persists.** Call the new `flush()` at the end of the unit of work
  that made the changes. Unflushed changes now raise an `E_USER_WARNING` naming the lost option keys.
  `setPersistOnDestruct(true)` restores the old behavior explicitly for hosts that cannot yet move the call.
  *Why:* in a long-lived process destruction time is unrelated to request time, so a merchant's configuration could be
  written back long after the request that changed it ended, and a process holding several scoped instances deferred
  every write to shutdown in whatever order PHP released them.
- **`WebhookManager` verifies against one tenant's keys, not a flat list.** The fourth constructor argument now accepts
  a `WebhookTenantResolverInterface` in addition to `string[]`; an array is wrapped in `StaticApiKeyResolver`, so
  single-shop wiring is unchanged. `getCrSignature()` gained an optional `?string $tenantKey`, and the "no API key
  configured" messages changed wording. `verifyRequest()` (protected) now returns a `VerifiedWebhookRequest` instead of
  `void`.
- **`PlatformInfoInterface` now extends `ConnectorInfoInterface`.** No member changed and no implementation needs
  editing; the parent simply names the subset a connector running outside the shop can also supply.

### Added

- **Scope-addressed instances for every remaining process-global singleton**, following the `ConfigurationManager`
  pattern (`getInstance(..., string $scope = '')` plus a per-scope `reset(?string $scope = null)`): `ErrorLogger`,
  `DebugLogger`, `SettingsManager`, `ProductTypeFilterManager`, `StatusManager`. Previously the *first* tenant to ask
  for one of these in a long-lived process owned it for every tenant after it — the first tenant's API key signed
  everyone's error reports, the first tenant's log file received everyone's debug output, the first tenant's product
  filters narrowed everyone's paywall, and the first tenant's `StatusAdapterInterface` received everyone's status
  notifications. Hosts worked around the last one by calling `StatusManager::reset()` before every webhook; that hack
  is no longer needed.
- **`ErrorLogger::init()` / `DebugLogger::init()` take a scope**, so each tenant's PSR-3 destination is its own.
- **`ScopedCache` and `CacheManager::forScope()` / `scoped()` / `forPool()`.** A cache view that carries its tenant,
  so the partition is a property of the caller rather than of the process. The static `CacheManager::get()`/`set()`
  accessors are deprecated: the scope they read is whatever the last `init()` set. `ScopedCache` also adds `delete()`
  and never throws on a failing backend.
- **`StatusApplicationContext` is per scope**, with instance methods `apply()`, `isActiveInScope()`, `getDepth()` and
  `StatusApplicationContext::forScope()`. `isActive(?string $scope = null)` gives the precise per-tenant answer when
  passed a scope and the previous tenant-blind answer when not. `AbstractStatusAdapter` takes a trailing `$scope` and
  enters that tenant's context. *Why:* the depth counter was a single process-global integer, so while tenant A's
  status change was being applied a genuine, unrelated status change for tenant B saw an active context and was
  suppressed.
- **`ErrorLogger::registerGlobalHandlers()` / `unregisterGlobalHandlers()` / `withGlobalHandlers()` /
  `hasGlobalHandlers()`.** Installing PHP's error, exception and shutdown handlers is a process-global side effect that
  attributes every fatal in the process to one tenant's identity, so it is now named as such, is idempotent per
  instance, and can be scoped to a unit of work. `initHandlers()` remains as a deprecated alias.
- **`ConnectorInfoInterface`, `HostedConnectorInfo` and `UserAgentBuilder`.** A connector deployed outside the shop —
  a multi-tenant SaaS service talking to merchants' platforms over HTTP — cannot know the platform version, the
  database version or the storefront's PHP version, so it could not implement `PlatformInfoInterface` and
  hand-assembled its User-Agent instead — duplicating library logic once per integration, and drifting from it
  immediately. `UserAgentBuilder` is now the single place that knows the format, and both variants render through it.
- **`ApiClientFactory::createSharedClient()`.** Returns the api-client's stateless `SharedClient`: no credential lives
  on the object, the tenant travels per call in an `ApiContext`, and one transport is shared. That lets a host register
  one client as a container service instead of allocating a client, a retry executor and a policy for every
  availability probe.
- **`maxTotalTransferTimeout` on `createClient()` / `createClientFromPlatformInfo()`.** Hosts on a tighter latency
  budget than the api-client's 15 s default can now size or disable the total transfer budget instead of inheriting it
  silently.
- **Tenant-aware webhook protection interfaces.** `TenantAwareRateLimiterInterface` (`consume(RateLimitKey,
  int $tokens): RateLimitVerdict`) and `TenantAwareReplayProtectionInterface` (tenant key, explicit TTL, and
  `purgeTenant()`). `WebhookManager` now emits a spec-correct 429 carrying `Retry-After`, `X-RateLimit-Limit` and
  `X-RateLimit-Remaining` — which the old boolean verdict could not express. Existing implementations keep working via
  `LegacyRateLimiterAdapter` / `LegacyReplayProtectionAdapter`.
- **`WebhookTenantResolverInterface`, `TenantWebhookContext`, `StaticApiKeyResolver`, `VerifiedWebhookRequest`.** A
  tenant resolved from the request, verified against that tenant's key alone. A `TenantWebhookContext` cannot be
  constructed without a usable key, and a resolver that cannot identify a tenant returns null — the request is then
  rejected, never retried against another merchant's keys.
- **Queue fairness and pacing.** `QueuedRequest` gained `$tenantKey` (carried into `dedupKey()`) and `$availableAt`;
  `OutboundRequestQueue::process()` cycles tenants round-robin, pauses a failing tenant's partition instead of the
  whole drain, schedules each requeued item forward with exponential backoff and jitter, and accepts an optional
  `?string $tenantKey` to drain one merchant alone. New `TenantPauseReporterInterface` raises the operational alert
  behind a paused partition. `QueueDrainResult` gained `$pausedTenants` and `$notDue`.
  *Why:* the drain walked the store front-to-back and stopped on the first transient failure. Correct for one shop; in
  a shared queue it meant one merchant with a rotated key stalled every other merchant's cancellations behind them,
  indefinitely, because the stalled item stayed at the front. Two merchants cancelling the same order number also
  collapsed into one entry, losing one of the two cancellations.
- **`OutboundRequestQueueProcessor` takes a `ScopedCache` and an optional tenant key**, so one merchant's outage
  cannot gate another merchant's drain through a shared cooldown.

- **Negative caching for failed API lookups in `SettingsManager`** — `getAdminProductTypes()`, `getAdminWidgetTypes()`,
  and `getAdminCreditors()` now cache a failure marker for 60 seconds after a failed API call, so a ComfinoPay API
  outage no longer causes every incoming storefront request to repeat the same failing lookup and pay the full retry
  escalation of the API client.
- `FailingHttpClient` test double for exercising retry behaviors in unit tests.

### Changed
- **`ApiTransientErrorClassifier` now uses the api-client's `ErrorClassifier`** instead of the deprecated
  `Psr18ErrorDetector`. The first constructor parameter accepts either (the detector delegates to the classifier, with
  a deliberately unchanged verdict), so existing call sites keep working.

### Fixed
- **`WebhookManager` signature verification now skips unusable API keys** — `null` and empty-string entries (which
  host platforms may pass for an unconfigured environment) are filtered out before signature calculation and
  verification. Previously, an empty API key would let any caller forge a valid signature without knowing a secret,
  and a `null` key would raise a `TypeError` before the authorization check could even run. `getCrSignature()` and
  `authorizeRequest()` now throw `AuthorizationError` when no usable API key remains.

### Deprecated

- `Comfino\Backend\Webhook\RateLimiterInterface` — use `TenantAwareRateLimiterInterface`.
- `Comfino\Backend\Webhook\ReplayProtectionInterface` — use `TenantAwareReplayProtectionInterface`.
- `CacheManager::get()` / `CacheManager::set()` — use `CacheManager::forScope($tenant)`.
- `ErrorLogger::initHandlers()` — use `registerGlobalHandlers()`.
- `StatusApplicationContext::run()` — use `StatusApplicationContext::forScope($scope)->apply()`.
- `WebhookManager::getReceivedCrSignature()` / `getCalculatedCrSignature()` — they read the *last* verified request
  from instance state, which in a shared manager may be another merchant's. Read `VerifiedWebhookRequest` instead.

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
  `POST /v1/error-logging-token`) instead of the raw ComfinoPay API key, so the API key itself never reaches the
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
  fields to the ComfinoPay API, matching the ecommerce API's structured `ShopPluginError` format
  (`Comfino-Message-Version` header). Requires `comfino/php-api-client` with the corresponding `ErrorCategory`,
  `ErrorSeverity`, `OperationContext` enums and extended `ShopPluginError` DTO.
- **Frontend error-reporting tokens:** `PaywallConfig` gains two new optional properties — `loggingToken` and `trackId` — included in `getAsArray()` and forwarded to the ComfinoPay Web SDK so browser-side errors can be correlated with backend API calls. `PaywallConfigBuilder::buildConfig()` accepts an optional `$trackId` and auto-generates the logging token.
- **`LOGGING_TOKEN` / `TRACK_ID` placeholders** added to `WidgetSdkInitScriptHelper` and wired into `WidgetSdkInitScript`'s `sdk.init()` call. The SDK `<script>` element now also sets `crossOrigin = 'anonymous'` for CORS-compliant error reporting.
- **`StatusApplicationContext`** (`Comfino\Shop\Order`): tracks whether an API-initiated order status change is currently being applied, so platform integrations can suppress reflexive outbound calls back to the ComfinoPay API from their order-persistence event hooks (e.g., avoid echoing a cancellation request for an order the API itself just reported as canceled). Exposes `run(callable)` and `isActive()`.
- **Outbound request queue** (`Comfino\Backend\Queue`): a platform-agnostic, durable FIFO retry queue for idempotent fire-and-forget API calls. `OutboundRequestQueue::submit()` attempts a call once with minimal timeouts and, on transient failure (timeout / network / 5xx / 429), persists it for later resend instead of blocking the shop request thread; `process()` drains pending requests one by one in insertion order, stopping at the first transient failure. Permanent 4xx errors are dropped; 404/409 on `cancel_order` are treated as already-done. Persistence is delegated to a platform-implemented `RetryQueueStorageInterface`; per-operation handling is done via `RetryableOperationHandlerInterface` handlers (`CancelOrderHandler` for `cancel_order` and `ReportErrorHandler` for `report_error`). `ErrorLogger` integrates with the queue when an `OutboundRequestQueue` is injected: error reports are always enqueued as pure fire-and-forget via `ReportErrorHandler` rather than attempted inline, so logging never blocks a shop request thread. Includes `OutboundRequestQueueProcessor` for cron/opportunistic draining, a `DeadLetterReporterInterface` hook for give-up notification, `OutboundRequestQueueFactory`, and a `ClockInterface` / `SystemClock` time abstraction.
- **Creditors support:** `SettingsManager::getCreditors()` fetches available creditors keyed by product type from the ComfinoPay API, with in-memory and `CacheManager` caching under the `'creditors'` key (tagged `admin_product_types`). Returns `null` when the API key is absent or the call fails; an empty array is a valid "no creditors configured" response.
- **Allowed-products configuration:** `SettingsManager::getAllowedProductsConfig()` reads the `COMFINO_ALLOWED_PRODUCTS_CONFIG` key from the injected `ConfigurationManager` and returns a `Comfino\Api\Dto\Payment\AllowedProductConfig[]` DTO list (or `null` when unconfigured). `SettingsManager::getAllowedProductsConfigForFrontend()` returns the same data as a plain array suitable for JSON / `window.comfinoPaywallData` embedding.
- **`AllowedProductsConfigBuilder`** (`Comfino\Backend\Payment`): static helper that converts the persisted array shape `[{type, maxTerm?, minTerm?, terms?}, …]` to `AllowedProductConfig[]` via `fromPersistedArray()`, and the reverse via `toFrontendArray()`. Malformed or type-less entries are silently skipped; returns `null` when the result is empty.
- **Paywall creditors & term constraints:** `PaywallConfig` gains two new optional readonly properties — `?array $creditors` and `?array $allowedProductsConfig` — both included in `getAsArray()`. `PaywallConfigBuilder::buildConfig()` accepts matching optional parameters at the end of its signature (backward compatible).
- **`Order::getAllowedProductsConfig()`:** the `Order` class accepts an optional `?array $allowedProductsConfig` constructor parameter (last position, defaults to `null`) and exposes it via the new `getAllowedProductsConfig(): ?array` getter defined on `OrderInterface`.
- **`OrderFactory::createOrder()` extension:** new optional `?array $allowedProductsConfig = null` parameter (last position). When provided, it is threaded through to the `Order` constructor so downstream code can pass `$order->getAllowedProductsConfig()` directly to `AbstractClient::createOrder()` / `validateOrder()`.
- **Platform metadata interfaces:** `PlatformInfoInterface` for exposing platform capabilities and metadata.
- **Webhook IP filtering:** `IpWhitelist` and `IpWhitelistInterface` for IP-based access control on webhooks with support for CIDR notation and multiple IP patterns.
- **Enhanced logging:** `CookieServiceModeChecker` for detecting and logging cookie-based service mode configuration.
- **Language configuration:** `LanguageProviderInterface` for pluggable language/localization settings.
- **`SdkUrlBuilder`** (`Comfino\Frontend`): cross-platform static helper that centralizes CDN URL construction for the ComfinoPay web SDK. The web SDK is a single, ESM-only bundle served from the primary `sdk.comfino.pl` host (sandbox `sdk.craty.pl`; the legacy `widget.*/sdk/v<n>/…` alias keeps serving via the edge rewrite). `getSdkScriptUrl(bool $sandboxMode, bool $devOverridesEnabled, int $version)` returns the `comfino-sdk.min.js` URL for production or sandbox (always loaded via `<script type="module">`); `getApiHostOverride()` returns a validated dev-environment API host override when active. Dev overrides (`COMFINO_DEV_SDK_SCRIPT_URL`, `COMFINO_DEV_API_HOST`) require both the server-set `COMFINO_DEV_ENV=TRUE` environment variable and the per-shop opt-in flag, and are validated through `UrlValidator`'s allow-list to prevent redirect to attacker-controlled hosts.
- **`FrontendHelper::getCheckoutLoaderStylesheetUrl(string $sdkScriptUrl): ?string`**: derives the URL of the shared CDN-served checkout stylesheet (`comfino-checkout.css`) from the SDK script URL's origin, so shop plugins do not need to hardcode the CDN host. Returns `null` when the input URL is empty or malformed.
- **Frontend environment builders:** 
  - `AbstractShopEnvironmentBuilder` for constructing shop environment metadata.
  - `PaywallConfigBuilder` for building paywall configuration with theme and capability resolution.
  - `CapabilityResolver` for resolving platform capabilities and feature flags.
  - `ThemeFamilyRules` for custom theme family and style mapping.
- **Shop domain builders:** `CartBuilderInterface`, `CustomerBuilderInterface`, `AbstractCartBuilder`, `AbstractCustomerBuilder`, and `AbstractStatusAdapter` for type-safe shop object construction.
- **Widget script helpers for the ComfinoPay Web SDK:** `WidgetSdkInitScript` and `WidgetSdkInitScriptHelper` for integrations targeting `comfino-sdk.min.js` — initializes via `window.Comfino.ComfinoSDK.getInstance()`, `sdk.init()`, and `sdk.createWidget()`. Use `WidgetFrontendInitScriptHelper` instead for the legacy `ComfinoWidgetFrontend.init()` interface.
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
  included as `shop_environment` in the endpoint's configuration response, giving the ComfinoPay API selector knowledge
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
- Initial release of the ComfinoPay Payment Gateway PHP Backend SDK.
- `ConfigurationManager` singleton for centralized API credentials and plugin settings storage via a pluggable `StorageAdapterInterface`.
- `CacheManager` singleton wrapping a PSR-6 `CacheItemPoolInterface` with optional PSR-6 cache-tag interop support.
- `DebugLogger` and `ErrorLogger` singletons backed by a PSR-3 `LoggerInterface`; `SensitiveDataProcessor` masks API keys and personal data before writing to logs.
- `LoggerFactory` for creating preconfigured logger instances.
- `ProductTypeFilterManager` with a composable filter chain: `FilterByProductType`, `FilterByCartValueLowerLimit`, `FilterByCartValueUpperLimit`, `FilterByExcludedCategory`.
- `CategoryTree` with lazy construction via `BuildStrategyInterface` and O(1) ID-indexed lookups; `CategoryFilter` for ancestor/descendant exclusion checks.
- `WebhookManager` for routing authenticated incoming webhook requests to registered `WebhookEndpointInterface` implementations with optional rate-limiting and replay-protection hooks.
- Built-in webhook endpoint implementations: `StatusNotification`, `Configuration`, `CacheInvalidate`.
- `ApiClientFactory`, `OrderFactory`, and `WebhookManagerFactory` for consistent object construction in e-commerce plugin integrations.
- `WidgetInitScript` and `WidgetInitScriptHelper` for building the ComfinoPay widget initialization script tag.
- `FrontendHelper` with logo URL and paywall auth hash helpers for frontend rendering.
- Concrete shop domain model implementations: `Order`, `Cart`, `CartItem`, `Product`, `Customer`, `Address`, `LoanParameters`, `Seller`.
- `Cart::getItemsCount()` and `Cart::getTotalItemsCount()` for distinct item count and total quantity respectively.
- `StatusManager` singleton mapping platform-specific order status adapters to ComfinoPay order statuses.
- `FileUtils` helper for safe file read/write operations within plugin contexts.
- `CacheItemType` enum for typed cache key namespacing.
- Depends on `comfino/php-api-client ^2.0` for all ComfinoPay REST API communication.
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

[Unreleased]: https://github.com/comfino/php-sdk/compare/3.2.1...HEAD
[3.2.1]: https://github.com/comfino/php-sdk/compare/3.2.0...3.2.1
[3.2.0]: https://github.com/comfino/php-sdk/compare/3.1.0...3.2.0
[3.1.0]: https://github.com/comfino/php-sdk/compare/3.0.0...3.1.0
[3.0.0]: https://github.com/comfino/php-sdk/compare/2.0.0...3.0.0
[2.0.0]: https://github.com/comfino/php-sdk/compare/1.0.0...2.0.0
[1.0.0]: https://github.com/comfino/php-sdk/releases/tag/1.0.0
