# Upgrading to v3.2.0

**Nothing is required.** 3.2.0 is a rebrand (Comfino → ComfinoPay) plus a queue fix; no public API, namespace, or
package name changed, and every new parameter is optional and trailing.

One thing is worth doing if you run a multi-tenant host:

### If you drain the outbound request queue for more than one merchant

`ReportErrorHandler` used to hold a single `ClientInterface`, so on the drain path — which runs under the platform's
cron scope, not the scope that enqueued the work — every merchant's error report was sent with the *default*
merchant's API key and rejected with 401. Pass a `Closure(?string $tenantKey): ClientInterface` to
`OutboundRequestQueueFactory::create()`'s new trailing `$tenantClientFactory` parameter to resolve the right client
per tenant at delivery time:

```php
$queue = $factory->create(
    // … required params …
    tenantClientFactory: fn (?string $tenantKey) => $clientRegistry->forTenant($tenantKey),
);
```

A single-shop install can ignore this — passing a plain `ClientInterface` (or omitting the parameter) keeps the
previous, correct behavior.

---

# Upgrading to v3.1.0

**Nothing is required.** 3.1.0 adds seams and fixes three defects; every new parameter is optional and trailing, every
new interface is opt-in, and a 3.0.0 integration compiles and behaves identically. One dependency constraint moves:
`comfino/php-api-client` is now `^3.1` (that release is itself additive).

Three things are worth doing anyway, in this order.

### 1. If you enable replay protection, check what it keys on

Until 3.1 the manager deduplicated on the `CR-Signature`, which is `sha3-256(apiKey + body)` — the *content*, not the
delivery. For an order-status notification, whose body is essentially `{externalId, status}`, two genuine deliveries
hash identically and the second is dropped as a replay. If you have replay protection on for `StatusNotification`,
you have been silently dropping repeated statuses.

Either turn it off for that endpoint and make your status handling order-aware, or pass a
`HeaderReplayKeyExtractor` once the sender carries a per-delivery identifier:

```php
$manager = (new WebhookManagerFactory())->createWebhookManager(
    // … required params …
    replayProtection: $myReplayStore,
    replayKeyExtractor: new HeaderReplayKeyExtractor(),
);
```

A request carrying no identifier is processed and not recorded, so wiring the extractor early changes nothing.

### 2. If you use both the IP allow-list and the rate limiter, share one resolver

They used to judge different addresses: the allow-list resolved forwarding headers, the limiter read `REMOTE_ADDR` raw.
Behind a proxy that meant every caller shared one rate-limit bucket.

```php
$ipResolver = ClientIpResolver::default();

$manager = (new WebhookManagerFactory())->createWebhookManager(
    // … required params …
    ipWhitelist: new IpWhitelist([IpWhitelist::COMFINO_SERVER_IP], ipResolver: $ipResolver),
    ipResolver: $ipResolver,
);
```

Pass `new ClientIpResolver([])` instead if your host already resolves the caller into `REMOTE_ADDR`.

### 3. If your merchants can run test payments, add the sandbox addresses

`IpWhitelist::forComfino()` allowed the production notification server only, so an enforced allow-list rejected every
sandbox notification — visible as "test payments never complete", not as a security event:

```php
$whitelist = IpWhitelist::forComfino(includeSandbox: true, enforce: false, logger: $logger);
```

`enforce: false` allows the request and logs the address that arrived. Roll out in observation, read the log, then
enforce.

### Also available

- **`TenantAwareWebhookEndpointInterface`** — a multi-tenant host can now register **one** `WebhookManager` as a shared
  service instead of rebuilding the whole webhook stack per request. `StatusNotification` accepts a
  `StatusAdapterResolverInterface` in place of a `StatusManager`. See `docs/webhooks.md`, "One manager for every
  merchant".
- **`Psr6CircuitBreakerStore` / `Psr6TokenBucketStore`** — shared state for the api-client's breaker and outbound
  limiter, which default to process-local. Read their docblocks on what PSR-6 cannot make exact.
- **`StatusManager::create()`** and `scopes()` / `scopeCount()` on every scope-addressed class — for hosts that build
  per request and need to prove they release what they register.
- **`maxBodyBytes:`** — the 1 MB body cap now also applies to a PSR-7 request you build and pass in, where previously
  there was no cap at all.

---

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
ComfinoPay signature authorized a request for **every** merchant: the request really is from ComfinoPay, so it verified, and
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
