# API client

The API client layer (`Comfino\Api\*`, `Comfino\Auth\*`, enums, and shop domain interfaces) is provided by the [`comfino/php-api-client`](https://github.com/comfino/php-api-client) package, which `comfino/php-sdk` requires as a dependency. If your integration needs only the HTTP API layer — without the plugin infrastructure (configuration manager, webhook routing, payment filters, frontend helpers) — you can depend on `comfino/php-api-client` directly:

```bash
composer require comfino/php-api-client
```

Full documentation for the standalone package: [github.com/comfino/php-api-client](https://github.com/comfino/php-api-client#readme)

---

## Creating the client

Use `ApiClientFactory` for a fully configured instance with retry support:

```php
use Comfino\Backend\Factory\ApiClientFactory;

$client = (new ApiClientFactory())->createClient(
    httpClient: $httpClient,           // PSR-18 HTTP client
    requestFactory: $requestFactory,   // PSR-17 request factory
    streamFactory: $streamFactory,     // PSR-17 stream factory
    apiKey: 'your-api-key',
    userAgent: 'MyPlugin/1.0.0',
    connectionTimeout: 1,              // seconds
    transferTimeout: 3,                // seconds
    maxRetries: 3
);

// Switch to sandbox for testing.
$client->enableSandboxMode();
```

Or instantiate `Client` directly (no retry, no custom user agent):

```php
use Comfino\Api\Client;

$client = new Client($httpClient, $requestFactory, $streamFactory, 'your-api-key');
```

## API operations

```php
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Enum\LoanType;
use Comfino\Enum\ProductListType;

// Account status check.
$isActive = $client->isShopAccountActive(
    cacheInvalidateUrl: 'https://my-shop.com/comfino/webhook/cache',
    configurationUrl: 'https://my-shop.com/comfino/webhook/config'
);

// Financial products listing.
$criteria = new LoanQueryCriteria(
    loanAmount: 150000,   // in cents
    loanTerm: 12,         // months
    loanType: LoanType::INSTALLMENTS_ZERO_PERCENT
);
$products = $client->getFinancialProducts($criteria);

// Financial product details for a specific cart.
$details = $client->getFinancialProductDetails($criteria, $cart);

// Create a loan application.
$response = $client->createOrder($order);
$applicationUrl = $response->applicationUrl; // Redirect customer here.

// Retrieve order details by shop order ID.
$orderDetails = $client->getOrder('ORDER-123');

// Cancel an order.
$client->cancelOrder('ORDER-123');

// Retrieve available product types (for plugin admin panel).
$productTypes = $client->getProductTypes(ProductListType::LIST_TYPE_WIDGET);

// Retrieve widget configuration.
$widgetKey = $client->getWidgetKey();
$widgetTypes = $client->getWidgetTypes();
```

## Error handling

All API errors throw exceptions implementing `HttpErrorExceptionInterface`:

```php
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\HttpErrorExceptionInterface;

try {
    $response = $client->createOrder($order);
} catch (AuthorizationError $e) {
    // Invalid or missing API key.
} catch (RequestValidationError $e) {
    // Order data rejected by the API (HTTP 400).
} catch (ServiceUnavailable $e) {
    // Comfino API is unavailable (HTTP 5xx).
} catch (HttpErrorExceptionInterface $e) {
    // Any other API error — $e->getStatusCode(), $e->getMessage()
}
```

| Exception                    | HTTP    | Description                |
|------------------------------|---------|----------------------------|
| `RequestValidationError`     | 400     | Invalid request data       |
| `AuthorizationError`         | 401     | Missing or invalid API key |
| `AccessDenied` / `Forbidden` | 402–403 | Permission issues          |
| `NotFound`                   | 404     | Resource not found         |
| `MethodNotAllowed`           | 405     | HTTP method not allowed    |
| `Conflict`                   | 409     | Resource state conflict    |
| `ServiceUnavailable`         | 500+    | Server-side error          |
| `ConnectionTimeout`          | —       | HTTP client timeout        |
| `RetryExhaustedException`    | —       | All retry attempts failed  |

## Retry mechanism

`ApiClientFactory` automatically configures exponential backoff retry. To configure it manually:

```php
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Api\Client;

$client = new Client(
    $httpClient,
    $requestFactory,
    $streamFactory,
    'your-api-key',
    retryExecutor: new RetryExecutor(
        new ExponentialBackoffRetryPolicy(
            new TimeoutConfig(connectionTimeout: 1, transferTimeout: 3),
            maxAttempts: 3
        )
    )
);
```

Connection timeout max: 30 s. Transfer timeout max: 60 s.
