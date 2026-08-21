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
   scope, so a status notification can only ever reach the adapter belonging to the merchant it names. */
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
- **Request body size**: bodies exceeding 1 MB are rejected before the signature check, guarding against memory exhaustion.

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

Both are optional (`null` by default). When omitted, no replay or rate-limit checks are performed — acceptable for low-volume or trusted-network deployments, but strongly recommended for public-facing endpoints.

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
| Request body over 1 MB       | 413 Payload Too Large |

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
