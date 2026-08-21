# Upgrading to v3.0.0

Version 3.0.0 makes the SDK safe to use in a process that serves more than one merchant. If you maintain a **plugin
installed in a shop** — one process, one shop, one API key, dying at the end of the request — most of this document does
not apply to you: the tenant scope is a trailing optional argument that defaults to the behavior you have today. Four
things do need attention, and they are listed first.

## What every integration must do

### 1. Bump `comfino/php-api-client` to `^3.0`

The api-client's own 3.0.0 removed `getOrder()` (reconcile order state from the status webhook instead of polling it),
renamed `Psr18ErrorDetector` to `ErrorClassifier`, and replaced `TimeoutAwareClientInterface` with
`TimeoutConfigurableClientInterface`. If you call any of those directly, see that package's changelog. The surface most
integrations use — `enableSandboxMode()`, `getFinancialProducts()`, `createOrder()`, `cancelOrder()` — is unchanged.

### 2. Call `ConfigurationManager::flush()`

`__destruct()` no longer writes pending changes. Add an explicit flush where the unit of work that made the changes
ends:

```php
$config->updateConfigurationOptions($submittedValues);
$config->flush();                       // was: implicit, at destruction
```

Unflushed changes now raise an `E_USER_WARNING` naming the option keys that were dropped, so a missed call is visible in
the log rather than silent. If your bootstrap genuinely has no place to put the call, `$config->setPersistOnDestruct(true)`
restores the old behavior — explicitly, which is the point.

*Why:* in a long-lived process, destruction time has nothing to do with request time. A merchant's configuration could
be written back long after the request that changed it had ended, and a process holding several scoped instances
deferred every write to shutdown, in whatever order PHP happened to release them.

### 3. Update your `RetryQueueStorageInterface` adapter

If you implement the outbound queue's storage over your platform's database, three signatures changed and one method is
new:

```php
public function peekBatch(int $limit, ?string $tenantKey = null, ?int $dueAt = null): array;
public function count(?string $tenantKey = null): int;
public function pendingTenantKeys(?int $dueAt = null): array;   // new
```

A single-shop adapter must accept the parameters but can ignore `$tenantKey` (it is always null for you) and return
`[null]` from `pendingTenantKeys()` when it has pending work, `[]` when it does not. Honoring `$dueAt` — filtering out
rows whose `available_at` is in the future — is recommended rather than required: the queue re-checks and skips a
not-yet-due request either way, but an adapter that ignores the gate spends a batch slot on work it cannot do yet.

Two new columns are worth adding while you are there: `tenant_key` (nullable) and `available_at` (integer). They are
persisted from `QueuedRequest` and are what make the fair drain and the per-item backoff work.

### 4. Handle `QueueErrorDisposition::PauseTenant`

If you implement `TransientErrorClassifierInterface`, or `match` over the enum anywhere, there is a new case. The
built-in `ApiTransientErrorClassifier` now returns it for HTTP 401 and 403, where it previously returned
`DropPermanent`.

*Why:* a refused credential is not a property of the request — the payload is fine, the key is wrong — so it succeeds
once the key is fixed. And it is not one request's problem: every request for that merchant fails identically until
then, so dropping them one at a time silently discarded a merchant's entire payment-relevant outbound stream while the
queue reported itself healthy. Pass a `TenantPauseReporterInterface` to the queue so a human hears about it.

## What a multi-tenant host should do

None of this is required — the defaults reproduce the previous single-tenant behavior — but in a process that serves
several merchants, skipping it means the hazards below are avoided only by discipline.

### Pass a scope to every manager

`ErrorLogger`, `DebugLogger`, `SettingsManager`, `ProductTypeFilterManager` and `StatusManager` now keep one instance
per scope, following the pattern `ConfigurationManager` already used:

```php
$logger  = ErrorLogger::getInstance($client, $path, $host, $platform, $modulePath, $env, null, null, null, $tenantId);
$status  = StatusManager::getInstance($adapter, $tenantId);
$filters = ProductTypeFilterManager::getInstance($tenantId);

ErrorLogger::init($psrLoggerForThisTenant, $tenantId);
DebugLogger::init($psrLoggerForThisTenant, $tenantId);
```

Without the scope, the **first** tenant to ask for one of these owned it for every tenant after it: the first tenant's
API key signed everyone's error reports, the first tenant's log file received everyone's debug output, the first
tenant's product filters narrowed everyone's paywall, and the first tenant's `StatusAdapterInterface` received
everyone's status notifications. If you called `StatusManager::reset()` before every webhook to defuse the last one,
you can drop that call — pass the scope instead.

### Take a scoped cache rather than the statics

```php
$cache = CacheManager::forScope($tenantId);   // or CacheManager::forPool($pool, $tenantId)
$cache->get('product_types.paywall.pl');
```

`CacheManager::get()` / `set()` still work and are deprecated. The scope they read is process state, fixed at `init()`,
so in a process serving more than one tenant it is whatever the last `init()` set — and a cache key that silently
belongs to the wrong tenant is the quietest bug in this list.

### Resolve the webhook's tenant instead of passing every key

```php
final class InstallationResolver implements WebhookTenantResolverInterface
{
    public function resolve(ServerRequestInterface $request): ?TenantWebhookContext
    {
        // Wherever your integration put the tenant: a path segment, a header, a query parameter.
        return $this->resolveByTenantKey($request->getAttribute('installation_id'));
    }

    public function resolveByTenantKey(?string $tenantKey = null): ?TenantWebhookContext
    {
        // Null asks for "the single tenant of this integration", which a multi-tenant host cannot answer.
        if ($tenantKey === null) {
            return null;
        }

        $apiKey = $this->keys->find($tenantKey);   // null when the tenant is unknown or has no key

        return $apiKey !== null ? TenantWebhookContext::forKey($tenantKey, $apiKey) : null;
    }
}

$manager = new WebhookManager(..., new InstallationResolver(), ...);
```

Passing every merchant's key in the old `string[]` was the natural multi-tenant wiring, and it meant **any** valid
Comfino signature authorized a request for **every** merchant: the request really is from Comfino, so it verified, and
was then handled as whichever tenant the URL named. Resolving first removes the whole class of bug. Return null for a
tenant you cannot identify or cannot load a key for — never fall back to trying other keys.

Single-shop plugins need nothing: passing a `string[]` still works and is wrapped in `StaticApiKeyResolver`.

### Move to the tenant-aware protection interfaces

`TenantAwareRateLimiterInterface::consume(RateLimitKey, int $tokens): RateLimitVerdict` and
`TenantAwareReplayProtectionInterface` (tenant key, explicit TTL, `purgeTenant()`) replace the tenant-blind pair. Your
existing implementations keep working — `WebhookManager` adapts them — but an adapted limiter has no `Retry-After` to
report, so the 429 it produces carries no rate-limit headers. Implementing the new interface is what gets a
spec-correct rejection.

### Register a shared `SharedClient` instead of building a client per call

```php
$shared  = (new ApiClientFactory())->createSharedClient($httpClient, $requestFactory, $streamFactory);
$context = new ApiContext($apiKey, $sandboxMode, tenantKey: $installationId);

$products = $shared->getFinancialProducts($context, $criteria);
```

No credential lives on the client, so one instance can be a container service and signing one merchant's order with
another merchant's key becomes impossible by construction rather than avoided by convention.

### Stop hand-assembling the User-Agent

A connector deployed outside the shop — a multi-tenant SaaS service reaching merchants' platforms over HTTP — could
not implement `PlatformInfoInterface`, because it cannot know the platform version, the database version, or the
storefront's PHP version, so it built the User-Agent string by hand. `HostedConnectorInfo` is the honest variant, and
`UserAgentBuilder` is the single place that knows the format:

```php
$info = new HostedConnectorInfo('IDO', $connectorVersion, $publicHost, 'pl');
$client = (new ApiClientFactory())->createClientFromPlatformInfo($info, $apiKey, $sandbox, ...);
```

### Scope the global error handlers to a unit of work

```php
$logger->withGlobalHandlers(fn () => $this->handleJobFor($tenantId));
```

`registerGlobalHandlers()` installs PHP's error, exception and shutdown handlers process-wide, which attributes *every*
fatal in the process to that one tenant's identity. In a worker, register per job and unregister at the end — never once
at boot. `initHandlers()` remains as a deprecated alias for the same call.

### Back the cooldown, breaker and limiter with a shared store

`OutboundRequestQueueProcessor` accepts a `ScopedCache` and an optional tenant key so one merchant's outage cannot gate
another merchant's drain. The api-client's circuit breaker and outbound limiter default to process-local stores; with
several workers, each learns independently that the API is down, which is most of the value lost. Point them at Redis
or Postgres if you adopt them.

---

# Upgrading to v2.0.0

## Breaking Changes

### Cache system: `cache/tag-interop` → `symfony/cache-contracts`

The `cache/tag-interop` package (abandoned) has been replaced with the actively maintained `symfony/cache-contracts`.

**What changed:**
- `CacheInvalidate` constructor now accepts any `Psr\Cache\CacheItemPoolInterface` instead of requiring `Cache\TagInterop\TaggableCacheItemPoolInterface`.

**What to do:**
Inject any `Psr\Cache\CacheItemPoolInterface`. For tag-based cache invalidation to work, the implementation must also implement `Symfony\Contracts\Cache\TagAwareCacheInterface` (e.g., Symfony's `TagAwareAdapter`).

If the cache pool does not support tagging, `CacheInvalidate` silently skips the invalidation step.

**Example:**
```php
// Before
use Cache\TagInterop\TaggableCacheItemPoolInterface;

$cache = new TaggableCacheItemPoolInterface(); // Required interface
$endpoint = new CacheInvalidate('name', '/url', $cache);

// After
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

$cache = new SomePoolImplementation(); // Just needs CacheItemPoolInterface
// If it also implements TagAwareCacheInterface, tagging will work automatically.
$endpoint = new CacheInvalidate('name', '/url', $cache);
```

For tag invalidation to function, use an implementation that implements both interfaces, such as Symfony's `TagAwareAdapter`:
```php
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$cache = new TagAwareAdapter(new FilesystemAdapter());
$endpoint = new CacheInvalidate('name', '/url', $cache);
```
