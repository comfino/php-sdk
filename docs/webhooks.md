# Webhook Handling

Comfino sends webhook requests to your plugin endpoints to notify about order status changes, request cache invalidation, or fetch plugin configuration. All incoming requests are authenticated with a CR-Signature header (SHA3-256 HMAC).

## Setup

Use `WebhookManagerFactory` to create a `WebhookManager`:

```php
use Comfino\Backend\Factory\WebhookManagerFactory;
use Comfino\Api\Serializer\Json;

$webhookManager = (new WebhookManagerFactory())->createWebhookManager(
    platformName: 'MyShop',
    platformVersion: '8.0.0',
    pluginVersion: '1.0.0',
    apiKeys: ['your-api-key'], // One shop's keys (e.g., prod + sandbox, or during a rotation).
    // A multi-tenant host passes a resolver here instead - see below.
    serverRequestFactory: $serverRequestFactory, // PSR-17
    streamFactory: $streamFactory, // PSR-17
    uriFactory: $uriFactory, // PSR-17
    responseFactory: $responseFactory, // PSR-17
    serializer: new Json()
);
```

## Registering endpoints

### StatusNotification — order status updates (POST/PUT/PATCH)

```php
use Comfino\Backend\Webhook\Endpoint\StatusNotification;
use Comfino\Shop\Order\StatusAdapterInterface;
use Comfino\Shop\Order\StatusManager;

class MyOrderStatusAdapter implements StatusAdapterInterface
{
    public function setStatus(string $orderId, string $status): void
    {
        // Write new status to the shop database.
        MyShop::updateOrderStatus($orderId, $status);
    }
}

/* A host serving several merchants passes the tenant as the second argument: StatusManager keeps one instance per
   scope, so a status notification can only ever reach the adapter belonging to the merchant it names. Prefer
   StatusManager::create() when you already hold the adapter for the request you are serving — getInstance() caches
   the first adapter per scope for the life of the process and never evicts it. A multi-tenant host is better served
   again by a StatusAdapterResolverInterface; see "One manager for every merchant" below. */
$statusManager = StatusManager::getInstance(new MyOrderStatusAdapter());

$webhookManager->registerEndpoint(new StatusNotification(
    name: 'status',
    endpointUrl: '/comfino/webhook/status',
    statusManager: $statusManager,
    forbiddenStatuses: StatusManager::DEFAULT_FORBIDDEN_STATUSES,
    ignoredStatuses: StatusManager::DEFAULT_IGNORED_STATUSES
));
```

### CacheInvalidate — clears cached API responses (GET)

```php
use Comfino\Backend\Webhook\Endpoint\CacheInvalidate;

$webhookManager->registerEndpoint(
    new CacheInvalidate('cache', '/comfino/webhook/cache', $cacheManager)
);
```

### Configuration — returns plugin settings to Comfino (GET)

```php
use Comfino\Backend\Webhook\Endpoint\Configuration;

$webhookManager->registerEndpoint(
    new Configuration('config', '/comfino/webhook/config', $configurationProvider)
);
```

## Processing a request

```php
// Process from PHP globals (typical plugin controller action).
$response = $webhookManager->processRequest();

// Or pass an explicit PSR-7 server request.
$response = $webhookManager->processRequest(serverRequest: $psrRequest);

// Route directly to a named endpoint.
$response = $webhookManager->processRequest(endpointName: 'status', serverRequest: $psrRequest);

// Send the PSR-7 response (framework-dependent).
```

## Security

Signature is verified automatically before any endpoint logic runs.

- **GET requests**: `SHA3-256(apiKey + vkey)` — where `vkey` is the `?vkey=` query parameter.
- **POST/PUT/PATCH requests**: `SHA3-256(apiKey + requestBody)`.
- Comparison uses `hash_equals()` (timing-safe).
- **The tenant is resolved before the signature is verified**, and verification then uses that tenant's key(s) only.
- Multiple API keys are tried in order *within one tenant* — useful when rotating keys or supporting both sandbox and
  production.
- **Request body size**: bodies exceeding 1 MB are rejected before the signature check, guarding against memory
  exhaustion. Since 3.1 this applies to a request you build and pass in as well as to one read from globals — before
  that it covered only the globals path, which most framework integrations do not take. Override the cap with
  `maxBodyBytes:`.
- **Client address**: resolved once, through `ClientIpResolver`, and used for both the IP allow-list and the rate-limit
  key. Pass the *same* resolver instance to `IpWhitelist` and to the manager (`ipResolver:`) so the two guards cannot
  judge different addresses; pass `new ClientIpResolver([])` if your host has already resolved the caller into
  `REMOTE_ADDR`.

On verification failure the manager returns `401 Unauthorized` or `403 Access Denied` before the endpoint is called.

### Tenant resolution (multi-tenant hosts)

A signature proves who **sent** a request, not whose shop it is about. So a manager that tries every configured key in
turn accepts any authentic Comfino signature for any merchant, and then hands the request to whichever tenant the URL
named. For a single-shop plugin that is harmless — there is one key. For a host serving many merchants it is a
cross-tenant authorization bug, and it used to be the natural way to wire the manager up.

`WebhookTenantResolverInterface` inverts the order: resolve the merchant from the request first, then verify against
that merchant's key alone.

```php
use Comfino\Backend\Webhook\TenantWebhookContext;
use Comfino\Backend\Webhook\WebhookTenantResolverInterface;
use Psr\Http\Message\ServerRequestInterface;

final class InstallationResolver implements WebhookTenantResolverInterface
{
    public function __construct(private readonly ApiKeyStore $keys)
    {
    }

    public function resolve(ServerRequestInterface $request): ?TenantWebhookContext
    {
        // Wherever your integration puts the tenant: a path segment, a header, a query parameter.
        return $this->resolveByTenantKey($request->getAttribute('installation_id'));
    }

    public function resolveByTenantKey(?string $tenantKey = null): ?TenantWebhookContext
    {
        // Null asks for "the single tenant of this integration", which a multi-tenant host cannot answer.
        if ($tenantKey === null) {
            return null;
        }

        $apiKey = $this->keys->find($tenantKey); // Null when unknown or when no key is provisioned.

        return $apiKey !== null ? TenantWebhookContext::forKey($tenantKey, $apiKey) : null;
    }
}

$webhookManager = (new WebhookManagerFactory())->createWebhookManager(
    // … required params …
    apiKeys: new InstallationResolver($apiKeyStore),
);
```

Rules that make this worth having:

- Return **null** for a tenant you cannot identify, or whose key you cannot load. The manager then answers `401`. Never
  fall back to trying another merchant's keys, and never return a placeholder key.
- Return more than one key only for **one merchant's** rotation. `TenantWebhookContext` refuses to be constructed
  without a usable key, so a misconfigured tenant cannot become an authorization decision.
- `resolve()` reads an untrusted request. The tenant identifier it carries selects *which* secret must match, which is
  safe — it cannot grant access on its own.

Single-shop plugins keep passing a `string[]`; it is wrapped in `StaticApiKeyResolver` and nothing changes.

### One manager for every merchant (3.1)

A resolver alone does not let you register the manager once. `WebhookManager` resolved and verified the tenant and then
called `WebhookEndpointInterface::processRequest()` without telling it any of that, so an endpoint that had to act for a
merchant was *constructed* for that merchant — and the manager, the resolver and the endpoints all became per-request
objects. Worse, it made the safe wiring counter-intuitive: the resolver had to be bound to one merchant and re-check the
request against it, because a resolver that honestly answered "whichever merchant the URL names" would let a
notification verified for merchant B be applied by merchant A's endpoint.

`TenantAwareWebhookEndpointInterface` closes that. An endpoint implementing it receives the `VerifiedWebhookRequest`:

```php
use Comfino\Backend\Webhook\StatusAdapterResolverInterface;
use Comfino\Backend\Webhook\TenantWebhookContext;
use Comfino\Shop\Order\StatusAdapterInterface;

final class InstallationStatusAdapters implements StatusAdapterResolverInterface
{
    public function __construct(private readonly InstallationStore $installations)
    {
    }

    public function resolve(TenantWebhookContext $tenant): ?StatusAdapterInterface
    {
        $installation = $this->installations->find($tenant->tenantKey);

        // Null is acknowledged, not refused: the sender retries every non-2xx and a deprovisioned merchant
        // will not come back.
        return $installation !== null ? new MyStatusAdapter($installation) : null;
    }
}

// Registered once, for every merchant. `StatusNotification` asks the resolver per request.
$webhookManager->registerEndpoint(new StatusNotification(
    name: 'status',
    endpointUrl: $endpointUrl,
    statusManager: new InstallationStatusAdapters($installations),
    forbiddenStatuses: [],
    ignoredStatuses: [],
));
```

Constructed with a `StatusManager` instead, `StatusNotification` behaves exactly as it always did. Custom endpoints opt
in by implementing `TenantAwareWebhookEndpointInterface::processTenantRequest()`; everything else is called through
`processRequest()` as before.

Caching adapters per tenant inside a resolver recreates the trap `StatusManager::getInstance()` documents — read its
docblock first, and prefer `StatusManager::create()` when you already hold the adapter.

### Replay protection and rate limiting

`WebhookManager` accepts optional replay-protection and rate-limiter implementations. Supply them to prevent replay
attacks and enforce per-client request limits. Both interfaces carry the tenant, so a shared Redis or SQL store is
partitioned per merchant instead of mixing every merchant's counters and signatures together:

```php
use Comfino\Backend\Webhook\RateLimitKey;
use Comfino\Backend\Webhook\RateLimitVerdict;
use Comfino\Backend\Webhook\TenantAwareRateLimiterInterface;
use Comfino\Backend\Webhook\TenantAwareReplayProtectionInterface;

final class RedisReplayProtection implements TenantAwareReplayProtectionInterface
{
    public function isDuplicate(string $signature, ?string $tenantKey = null): bool
    {
        return (bool) $this->redis->exists($this->key($signature, $tenantKey));
    }

    public function markProcessed(string $signature, ?string $tenantKey = null, ?int $ttlSeconds = null): void
    {
        $this->redis->setex(
            $this->key($signature, $tenantKey),
            $ttlSeconds ?? self::DEFAULT_TTL_SECONDS,
            1
        );
    }

    public function purgeTenant(?string $tenantKey = null): int
    {
        // Deprovisioning a merchant should be a complete operation, not a day of orphaned hashes expiring.
        return $this->redis->del(...$this->redis->keys("webhook:sig:{$tenantKey}:*"));
    }

    private function key(string $signature, ?string $tenantKey): string
    {
        return sprintf('webhook:sig:%s:%s', $tenantKey ?? '-', $signature);
    }
}

final class MyRateLimiter implements TenantAwareRateLimiterInterface
{
    public function consume(RateLimitKey $key, int $tokens = 1): RateLimitVerdict
    {
        /* Never block waiting for a token: a webhook handler that waits has turned a rate limit into a latency
           problem for Comfino's delivery infrastructure, which will time out and redeliver. */
        [$allowed, $remaining, $retryAfter] = $this->bucket->take($key->toStorageKey(), $tokens);

        return $allowed
            ? RateLimitVerdict::accept($remaining, self::LIMIT)
            : RateLimitVerdict::reject($retryAfter, self::LIMIT);
    }
}

$webhookManager = (new WebhookManagerFactory())->createWebhookManager(
    // … required params …
    replayProtection: new RedisReplayProtection($redis),
    rateLimiter: new MyRateLimiter(),
    replayTtlSeconds: 86400,
);
```

The verdict is what lets the manager write a spec-correct `429`: a rejection carries `Retry-After`,
`X-RateLimit-Limit` and `X-RateLimit-Remaining` when the limiter reports them, and no such header when it does not — a
`Retry-After: 0` is worse than a missing one, because a caller reads it as a fact.

The older `ReplayProtectionInterface` and `RateLimiterInterface` are deprecated but still accepted; the manager wraps
them. What the wrappers cannot invent is the information those interfaces never carried, so an adapted limiter's
rejection has no `Retry-After`, and an adapted replay store stays unpartitioned.

Both are optional (`null` by default). When omitted, no replay or rate-limit checks are performed — acceptable for
low-volume or trusted-network deployments, but strongly recommended for public-facing endpoints.

The rate limit is consumed **once per request**, for the endpoint the request routes to. Before 3.1 it was consumed once
per endpoint *tried*, so a manager with several registered endpoints charged several tokens for one request, against the
names of endpoints that did not handle it.

#### What counts as the same delivery

**Read this before enabling replay protection.** Until 3.1 the manager deduplicated on the `CR-Signature`, which is
`sha3-256(apiKey + body)` — a function of the *content*, not of the delivery. Where every payload is unique that is
fine. For an order-status notification, whose body is essentially `{externalId, status}`, it is not: two genuine
notifications carrying the same body hash identically, and the second is then rejected as a replay. The failure is
silent — an order status that never arrives.

`ReplayKeyExtractorInterface` makes the key a choice:

```php
use Comfino\Backend\Webhook\HeaderReplayKeyExtractor;

$webhookManager = (new WebhookManagerFactory())->createWebhookManager(
    // … required params …
    replayProtection: new RedisReplayProtection($redis),
    // Keys on a per-delivery identifier instead of the payload hash. A request carrying none is processed and not
    // recorded, so wiring this before the API sends such a header changes nothing.
    replayKeyExtractor: new HeaderReplayKeyExtractor(),
);
```

The default remains `SignatureReplayKeyExtractor`, which is the 3.0 behavior. Until a per-delivery identifier exists,
the recommendation for status notifications is to leave replay protection off and make the shop's own status handling
idempotent or order-aware — a guard there cannot mistake two events for one.

### Restricting the source address

```php
use Comfino\Backend\Webhook\ClientIpResolver;
use Comfino\Backend\Webhook\IpWhitelist;

$ipResolver = ClientIpResolver::default();

$whitelist = IpWhitelist::forComfino(
    // Needed by any installation whose merchants can run a test payment: the sandbox calls from different addresses.
    includeSandbox: true,
    // Start in observation. A mismatch is logged with the address that arrived and the request proceeds; enforce once
    // the log has confirmed who really calls you.
    enforce: false,
    logger: $logger,
);

$webhookManager = (new WebhookManagerFactory())->createWebhookManager(
    // … required params …
    ipWhitelist: new IpWhitelist(
        [IpWhitelist::COMFINO_SERVER_IP, '198.51.100.0/24'], // ranges allowed since 3.1
        ipResolver: $ipResolver
    ),
    ipResolver: $ipResolver, // the same instance, so the allow-list and the limiter judge the same address
);
```

An address the resolver cannot determine counts as a mismatch, not an exemption — a check that passes when it cannot
run is not a check.

## HTTP status codes returned

| Scenario                     | Status                |
|------------------------------|-----------------------|
| GET endpoint success         | 200 OK                |
| POST endpoint success        | 201 Created           |
| PUT/PATCH/DELETE (no body)   | 204 No Content        |
| PUT/PATCH/DELETE (with body) | 200 OK                |
| Missing/invalid signature    | 401 Unauthorized      |
| Access denied                | 403 Forbidden         |
| Endpoint not found           | 404 Not Found         |
| Request payload error        | 400 Bad Request       |
| Rate limit exceeded          | 429 Too Many Requests |
| Body over the size cap       | 413 Payload Too Large |

## Custom endpoints

Implement `WebhookEndpointInterface` or extend `WebhookEndpoint`:

```php
use Comfino\Backend\Webhook\WebhookEndpoint;
use Psr\Http\Message\ServerRequestInterface;

class MyEndpoint extends WebhookEndpoint
{
    public function __construct()
    {
        parent::__construct('my-endpoint', '/comfino/webhook/my-endpoint');

        $this->methods = ['POST'];
    }

    public function processRequest(ServerRequestInterface $request, ?string $endpointName = null): ?array
    {
        $payload = parent::processRequest($request, $endpointName);
        // Handle $payload ...
        return ['received' => true];
    }
}

$webhookManager->registerEndpoint(new MyEndpoint());
```

An endpoint that needs to know which merchant the request was verified for implements
`TenantAwareWebhookEndpointInterface` as well, and the manager calls that method instead:

```php
use Comfino\Backend\Webhook\TenantAwareWebhookEndpointInterface;
use Comfino\Backend\Webhook\VerifiedWebhookRequest;
use Comfino\Backend\Webhook\WebhookEndpoint;
use Psr\Http\Message\ServerRequestInterface;

class MyTenantAwareEndpoint extends WebhookEndpoint implements TenantAwareWebhookEndpointInterface
{
    public function processTenantRequest(
        ServerRequestInterface $request,
        ?string $endpointName,
        VerifiedWebhookRequest $verified
    ): ?array {
        $payload = parent::processRequest($request, $endpointName);

        /* $verified->tenant is the merchant whose key the signature was checked against — the only tenant it is safe
           to act on. Anything read out of the payload is attacker-controlled until then, and is not re-checked after.
           $verified->tenant->getMetadata() carries whatever your resolver attached. */
        MyShop::forTenant($verified->tenantKey())->handle($payload);

        return null;
    }
}
```
