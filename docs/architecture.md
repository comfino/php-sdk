# Architecture

## Overview

`comfino/php-sdk` provides all the backend building blocks needed to integrate any PHP application or payment plugin with the Comfino API. It is equally suited for custom platform integrations, headless backends, and installable payment plugins for open-source e-commerce platforms.

The SDK has **no concrete HTTP client, logger, or cache** — all are injected via PSR interfaces, so it works with whichever implementations are already present in your application stack.

The full `Comfino\` namespace is split across two packages: the lower-level HTTP API layer lives in [`comfino/php-api-client`](https://github.com/comfino/php-api-client) (required automatically by this SDK as a Composer dependency); everything else — backend services, concrete domain implementations, frontend helpers — is in this package.

## Package structure

`comfino/php-sdk` builds on top of [`comfino/php-api-client`](https://github.com/comfino/php-api-client), which provides the HTTP API layer. The two packages together cover the full `Comfino\` namespace:

**`comfino/php-api-client`** — API client layer (external dependency):
```
src/
├── Api/            # PSR-18 API client, request/response classes, DTOs, retry, exceptions
│   ├── Dto/        # Immutable readonly DTOs (Order, Payment, Plugin)
│   ├── Exception/  # HTTP-layer exceptions
│   ├── Request/    # Outgoing request types (one class per API endpoint)
│   ├── Response/   # Incoming response types (one class per API endpoint)
│   ├── Retry/      # Exponential backoff retry policy (PSR-18 native, no curl)
│   └── Serializer/
├── Auth/           # Webhook CR-Signature verifier, paywall auth key generator, exception sanitizer
├── Enum/           # PHP 8.1 backed string enums: LoanType, OrderStatus, WidgetType, etc.
└── Shop/Order/     # Domain model interfaces (OrderInterface, CartInterface, …) and CartTrait
```

**`comfino/php-sdk`** — plugin SDK layer (this package):
```
src/
├── Backend/            # Backend services for Comfino integration implementations
│   ├── Cache/          # PSR-6 cache manager
│   ├── Configuration/  # Configuration singleton + StorageAdapterInterface
│   ├── Factory/        # ApiClientFactory, OrderFactory, WebhookManagerFactory
│   ├── Log/            # PSR-3 logger wrappers (debug, error, sensitive data processor)
│   ├── Payment/        # Product type filter chain + built-in filters
│   └── Webhook/        # WebhookManager, WebhookEndpoint base, built-in endpoints
├── Enum/               # SDK-specific enums only: CacheItemType
├── Frontend/           # Widget init script builder, logo / paywall auth hash helpers
└── Shop/               # Concrete domain implementations (Order, Cart, Customer, Address, …)
    ├── Order/          # Order, Cart, Customer, LoanParameters, Seller + StatusManager
    └── Product/        # Category, CategoryTree, CategoryFilter, CategoryManager
tests/
├── Unit/               # PHPUnit unit tests mirroring the src/ structure
└── Integration/        # Integration tests exercising comfino/php-api-client against the sandbox API
```

## Design principles

| Principle                          | Implementation                                                                        |
|------------------------------------|---------------------------------------------------------------------------------------|
| **HTTP client agnostic**           | `AbstractClient` depends only on `Psr\Http\Client\ClientInterface`.                   |
| **PSR interfaces everywhere**      | No concrete HTTP client, logger, or cache in base SDK.                                |
| **Immutable DTOs**                 | API layer DTOs use PHP 8.1 `readonly` properties.                                     |
| **Native enums**                   | `Comfino\Enum\*` — PHP 8.1 backed string enums (no custom base class).                |
| **Explicit integration contracts** | `StatusAdapterInterface`, `StorageAdapterInterface` for application-specific logic.   |
| **Testable singletons**            | `StatusManager`, `ConfigurationManager`, `ProductTypeFilterManager` expose `reset()`. |
| **Only V3 paywall**                | No legacy V1/V2 iframe rendering; only `PaywallAuthKeyGenerator` (HMAC-SHA3-256).     |

## Key design patterns

- **Factory classes** (`ApiClientFactory`, `OrderFactory`, `WebhookManagerFactory`) — single entry points for constructing complex objects; shield consumers from constructor changes.
- **Strategy pattern** — `BuildStrategyInterface` (category tree), `SerializerInterface` (request/response body), `RetryPolicyInterface` (retry behavior).
- **Chain of responsibility** — `ProductTypeFilterManager` runs an ordered list of `ProductTypeFilterInterface` implementations.
- **Adapter pattern** — `StatusAdapterInterface`, `StorageAdapterInterface` decouple the SDK from application-specific storage and order management.
- **Trait-based sharing** — `CartTrait` provides cart-to-array serialization used by multiple API request classes.

## Enum naming

All enums live in `Comfino\Enum\` with short names and no `Enum` suffix. Most are defined in `comfino/php-api-client`; `CacheItemType` is SDK-specific and lives in this package:

| Enum              | Package          | Values                                                                                         |
|-------------------|------------------|------------------------------------------------------------------------------------------------|
| `LoanType`        | `php-api-client` | `INSTALLMENTS_ZERO_PERCENT`, `CONVENIENT_INSTALLMENTS`, `PAY_LATER`, `COMPANY_INSTALLMENTS`, … |
| `OrderStatus`     | `php-api-client` | `WAITING_FOR_FILLING`, `WAITING_FOR_CONFIRMATION`, `ACCEPTED`, `REJECTED`, `RESIGN`, `PAID`, … |
| `WidgetType`      | `php-api-client` | `WIDGET_SIMPLE`, `WIDGET_MIXED`, `WIDGET_WITH_CALCULATOR`, `WIDGET_WITH_EXTENDED_CALCULATOR`   |
| `ProductListType` | `php-api-client` | `PAYWALL`, `WIDGET`                                                                            |
| `CacheItemType`   | `php-sdk`        | `ADMIN_PRODUCT_TYPES`, `ADMIN_WIDGET_TYPES`                                                    |
