<a href="https://developers.comfino.pl">
  <img src="assets/comfino_logo.svg" alt="Comfino" width="220">
</a>

# Comfino PHP SDK

[![Latest Version](https://img.shields.io/github/release/comfino/php-sdk.svg)](https://github.com/comfino/php-sdk/releases)
[![PHP Version](https://img.shields.io/packagist/dependency-v/comfino/php-sdk/php.svg)](https://packagist.org/packages/comfino/php-sdk)
[![Build Status](https://github.com/comfino/php-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/comfino/php-sdk/actions/workflows/tests.yml)
[![Software License](https://img.shields.io/badge/license-BSD%203--Clause-orange.svg)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/comfino/php-sdk.svg)](https://packagist.org/packages/comfino/php-sdk)
[![API Documentation](https://img.shields.io/badge/API-documentation-5a9e33)](https://developers.comfino.pl)

**Comfino PHP SDK with backend routines for e-commerce platforms integration**

A complete PHP backend library for integrating any application or e-commerce platform with the Comfino payment gateway API. Whether you are building a custom integration for a closed platform, writing a payment plugin for an open-source e-commerce system, or embedding Comfino financing into your own application, this SDK provides all the backend building blocks you need.

The library covers the full integration lifecycle: API communication, secure webhook handling, configuration management, payment product filtering, category-based eligibility checks, and frontend widget support. All integration points are defined as interfaces, so the SDK adapts to any PHP application stack without imposing a concrete HTTP client, logger, or cache implementation.

> **Lightweight integration?** If you only need the HTTP API layer — requests, responses, DTOs, retry, and auth — without the full plugin infrastructure, you can depend directly on the lower-level package:
>
> ```bash
> composer require comfino/php-api-client
> ```
>
> `comfino/php-api-client` is the standalone API client that this SDK builds upon. It ships the PSR-18 HTTP client, all request/response classes, error types, and the webhook signature verifier, but nothing else. See the [php-api-client documentation](https://github.com/comfino/php-api-client#readme) for details.

## Features

- PSR-18 HTTP Client / PSR-7 Messages / PSR-17 Factories support.
- PSR-6 cache and PSR-3 logging interfaces.
- Production and sandbox environment support.
- Exponential backoff retry for transient API errors.
- Secure webhook handling with CR-Signature (SHA3-256) verification.
- Configuration manager with a pluggable storage adapter.
- Payment product type filter chain based on cart value and category.
- Hierarchical category tree with ancestor/descendant traversal.
- Frontend helpers for widget init script and paywall logo authentication.

## Requirements

- PHP 8.1 or higher
- Extensions: `ext-json`, `ext-sodium`, `ext-zlib`
- PSR-18 HTTP Client and PSR-17 HTTP Factories implementations
- Composer

## Installation

```bash
composer require comfino/php-sdk
```

Suggested companion packages:

```bash
composer require sunrise/http-client-curl                  # PSR-18 cURL client
composer require monolog/monolog                           # PSR-3 logger
composer require cache/filesystem-adapter league/flysystem # PSR-6 filesystem cache
```

## Quick start

```php
use Comfino\Backend\Factory\ApiClientFactory;
use Comfino\Backend\Factory\OrderFactory;
use Comfino\Enum\LoanType;

// 1. Create the API client.
$client = (new ApiClientFactory())->createClient(
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
    apiKey: 'your-api-key'
);

// 2. Build an order.
$order = (new OrderFactory())->createOrder(
    orderId: 'ORDER-123',
    orderTotal: 150000, // in cents/grosz
    deliveryCost: 1500, // in cents/grosz
    loanTerm: 12, // months
    loanType: LoanType::INSTALLMENTS_ZERO_PERCENT,
    cartItems: $cartItems,
    customer: $customer,
    returnUrl: 'https://my-shop.com/order/confirm',
    notificationUrl: 'https://my-shop.com/comfino/webhook/status'
);

// 3. Submit the loan application.
$response = $client->createOrder($order);
header('Location: ' . $response->applicationUrl);
```

## Documentation

| Topic                                             | Guide                                                                                          |
|---------------------------------------------------|------------------------------------------------------------------------------------------------|
| Architecture, design patterns, namespace map      | [docs/architecture.md](docs/architecture.md)                                                   |
| API client: all operations, error handling, retry | [docs/api-client.md](docs/api-client.md)                                                       |
| Building order, cart and customer objects         | [docs/order-and-cart.md](docs/order-and-cart.md)                                               |
| Webhook handling and custom endpoints             | [docs/webhooks.md](docs/webhooks.md)                                                           |
| Configuration management                          | [docs/configuration.md](docs/configuration.md)                                                 |
| Payment filtering and category tree               | [docs/payment-filtering.md](docs/payment-filtering.md)                                         |
| Standalone API client (lightweight integration)   | [comfino/php-api-client](https://github.com/comfino/php-api-client#readme)                     |

## Development

The `bin/` wrappers delegate to Docker containers when `docker-compose` is available, or fall back to the host PHP. Two containers are used:

- **`php-sdk`** — standard container, no Xdebug. Start it once with `docker-compose up -d`.
- **`php-sdk-coverage`** — built with Xdebug (`XDEBUG_MODE=coverage`). Started on demand automatically by `bin/phpunit` whenever a `--coverage*` flag is detected; no manual `up` needed.

```bash
# Start the standard development container.
docker-compose up -d

# Install dependencies.
./bin/composer install

# Run all tests.
./bin/composer test

# Run unit tests only.
./bin/phpunit --testsuite Unit

# Run integration tests against the sandbox (requires a sandbox API key).
COMFINO_SANDBOX_API_KEY=your-key ./bin/phpunit --testsuite Integration

# Generate HTML coverage report (Xdebug container starts automatically).
./bin/phpunit --coverage-html coverage

# Check PSR-12 code style.
./bin/composer cs

# Auto-fix PSR-12 violations.
./bin/composer cs-fix

# Run PHPStan static analysis (level 6).
./bin/composer analyse
```

## PSR standards

* **PSR-4** autoloading
* **PSR-6** cache
* **PSR-7** HTTP messages
* **PSR-17** HTTP factories
* **PSR-18** HTTP client
* **PSR-12** coding style

## Changelog

See [CHANGELOG](CHANGELOG.md) for recent changes.

## License

BSD 3-Clause License. See [LICENSE](LICENSE) for details.

## Support

Bug reports and feature requests: [GitHub issue tracker](https://github.com/comfino/php-sdk/issues).

## Contributing

The [GitHub repository](https://github.com/comfino/php-sdk) is a read-only public mirror that receives automated clean-snapshot releases. Please report bugs and suggest improvements via the [issue tracker](https://github.com/comfino/php-sdk/issues).
