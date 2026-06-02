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
    apiKeys: ['your-api-key'],                    // Supports multiple keys (e.g. prod + sandbox).
    serverRequestFactory: $serverRequestFactory,  // PSR-17
    streamFactory: $streamFactory,                // PSR-17
    uriFactory: $uriFactory,                      // PSR-17
    responseFactory: $responseFactory,            // PSR-17
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
- Multiple API keys are tried in order — useful when rotating keys or supporting both sandbox and production.
- **Request body size**: bodies exceeding 1 MB are rejected before the signature check, guarding against memory exhaustion.

On verification failure the manager returns `401 Unauthorized` or `403 Access Denied` before the endpoint is called.

### Replay protection and rate limiting

`WebhookManager` accepts optional `ReplayProtectionInterface` and `RateLimiterInterface` implementations. Supply them to prevent replay attacks and enforce per-client request limits:

```php
use Comfino\Backend\Webhook\ReplayProtectionInterface;
use Comfino\Backend\Webhook\RateLimiterInterface;

class RedisReplayProtection implements ReplayProtectionInterface
{
    public function isDuplicate(string $signature): bool
    {
        return (bool) $this->redis->exists("webhook:sig:$signature");
    }

    public function markProcessed(string $signature): void
    {
        // Store with TTL that exceeds your replay window (e.g., 10 minutes).
        $this->redis->setex("webhook:sig:$signature", 600, 1);
    }
}

class MyRateLimiter implements RateLimiterInterface
{
    public function isAllowed(string $endpointName, string $clientIdentifier): bool
    {
        // Return false to block; implement token bucket or sliding window as needed.
        return true;
    }
}

$webhookManager = (new WebhookManagerFactory())->createWebhookManager(
    // … required params …
    replayProtection: new RedisReplayProtection($redis),
    rateLimiter: new MyRateLimiter()
);
```

Both are optional (`null` by default). When omitted, no replay or rate-limit checks are performed — acceptable for low-volume or trusted-network deployments, but strongly recommended for public-facing endpoints.

## HTTP status codes returned

| Scenario                     | Status           |
|------------------------------|------------------|
| GET endpoint success         | 200 OK           |
| POST endpoint success        | 201 Created      |
| PUT/PATCH/DELETE (no body)   | 204 No Content   |
| PUT/PATCH/DELETE (with body) | 200 OK           |
| Missing/invalid signature    | 401 Unauthorized |
| Access denied                | 403 Forbidden    |
| Endpoint not found           | 404 Not Found    |
| Request payload error        | 400 Bad Request  |

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
