# Architecture

## Overview

`comfino/php-sdk` provides all the backend building blocks needed to integrate any PHP application or payment plugin with the ComfinoPay API. It is equally suited for custom platform integrations, headless backends, and installable payment plugins for open-source e-commerce platforms.

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
├── Backend/            # Backend services for ComfinoPay integration implementations
│   ├── Cache/          # PSR-6 cache manager + tenant-scoped cache view
│   ├── Clock/          # Injectable clock, so time-dependent behavior is testable
│   ├── Configuration/  # Scope-addressed configuration manager + StorageAdapterInterface
│   ├── Factory/        # ApiClientFactory, OrderFactory, WebhookManagerFactory, OutboundRequestQueueFactory
│   ├── Log/            # PSR-3 logger wrappers (debug, error, sensitive data processor)
│   ├── Payment/        # Product type filter chain + built-in filters
│   ├── Queue/          # Durable outbound request queue, fair across tenants
│   ├── Settings/       # Product/widget type lookups with caching
│   └── Webhook/        # WebhookManager, tenant resolver, WebhookEndpoint base, built-in endpoints
├── Enum/               # SDK-specific enums only: CacheItemType
├── Frontend/           # Widget init script builder, logo / paywall auth hash helpers
├── Platform/           # Integration metadata: PlatformInfoInterface, HostedConnectorInfo, UserAgentBuilder
└── Shop/               # Concrete domain implementations (Order, Cart, Customer, Address, …)
    ├── Order/          # Order, Cart, Customer, LoanParameters, Seller + StatusManager
    └── Product/        # Category, CategoryTree, CategoryFilter, CategoryManager
tests/
├── Unit/               # PHPUnit unit tests mirroring the src/ structure
└── Integration/        # Integration tests exercising comfino/php-api-client against the sandbox API
```

## Design principles

| Principle                          | Implementation                                                                                                                                                                                      |
|------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **HTTP client agnostic**           | `AbstractClient` depends only on `Psr\Http\Client\ClientInterface`.                                                                                                                                 |
| **PSR interfaces everywhere**      | No concrete HTTP client, logger, or cache in base SDK.                                                                                                                                              |
| **Immutable DTOs**                 | API layer DTOs use PHP 8.1 `readonly` properties.                                                                                                                                                   |
| **Native enums**                   | `Comfino\Enum\*` — PHP 8.1 backed string enums (no custom base class).                                                                                                                              |
| **Explicit integration contracts** | `StatusAdapterInterface`, `StorageAdapterInterface` for application-specific logic.                                                                                                                 |
| **Scope-addressed instances**      | `ConfigurationManager`, `SettingsManager`, `StatusManager`, `ProductTypeFilterManager`, `ErrorLogger`, `DebugLogger` keep one instance per tenant scope, and expose `reset(?string $scope = null)`. |
| **No shared credential state**     | The tenant travels per call (`ApiContext`, `TenantWebhookContext`, `QueuedRequest::$tenantKey`) rather than living on a shared object.                                                              |
| **Only V3 paywall**                | No legacy V1/V2 iframe rendering; only `PaywallAuthKeyGenerator` (HMAC-SHA3-256).                                                                                                                   |

## Key design patterns

- **Factory classes** (`ApiClientFactory`, `OrderFactory`, `WebhookManagerFactory`) — single entry points for constructing complex objects; shield consumers from constructor changes.
- **Strategy pattern** — `BuildStrategyInterface` (category tree), `SerializerInterface` (request/response body), `RetryPolicyInterface` (retry behavior).
- **Chain of responsibility** — `ProductTypeFilterManager` runs an ordered list of `ProductTypeFilterInterface` implementations.
- **Adapter pattern** — `StatusAdapterInterface`, `StorageAdapterInterface` decouple the SDK from application-specific storage and order management; `LegacyRateLimiterAdapter` / `LegacyReplayProtectionAdapter` present the deprecated webhook interfaces as their tenant-aware replacements.
- **Resolver over scan** — `WebhookTenantResolverInterface` identifies the merchant *before* a signature is verified, so verification is narrowed to one tenant's secret instead of trying every configured key.
- **Trait-based sharing** — `CartTrait` provides cart-to-array serialization used by multiple API request classes.

## Multitenancy

The SDK is usable from two very different process shapes, and the difference is worth stating because it drives most of
the design above.

A **plugin installed in a shop** serves one merchant, holds one API key, and dies at the end of the request. Process
state and a per-request instance are the same thing, so a singleton is harmless and the defaults throughout the SDK are
tuned for it: every tenant scope defaults to the empty string, and every tenant key to null.

A **long-lived multi-tenant host** — a SaaS connector serving many merchants, a queue worker draining their jobs —
serves one merchant after another in a single process. There, anything held in process state is shared by tenants that must not
share it: an API key, a log destination, a cache entry, a recursion guard, a queue partition. The SDK's answer is
consistent across every subsystem: the tenant is an explicit argument, and it selects the instance.

| Concern                                                   | How the tenant travels                                             |
|-----------------------------------------------------------|--------------------------------------------------------------------|
| Configuration, settings, filters, status routing, logging | `getInstance(..., string $scope)` + per-scope `reset()`            |
| Cache entries                                             | `CacheManager::forScope($tenant)` → `ScopedCache`                  |
| API credentials                                           | `ApiContext` passed per call to `SharedClient`                     |
| Inbound webhooks                                          | `WebhookTenantResolverInterface` → `TenantWebhookContext`          |
| Rate limiting, replay protection                          | `RateLimitKey::$tenantKey`, `TenantAwareReplayProtectionInterface` |
| Outbound queue                                            | `QueuedRequest::$tenantKey`, round-robin drain, per-tenant pause   |
| Status-change suppression                                 | `StatusApplicationContext::forScope($tenant)`                      |

Two things stay process-global by nature, and both are opt-in for that reason: `ErrorLogger`'s PHP error handlers
(`registerGlobalHandlers()`, ideally bracketed per unit of work with `withGlobalHandlers()`), and the api-client's
circuit breaker and outbound limiter, whose default stores are per process and should be pointed at a shared backend in
a multi-worker deployment.

## Enum naming

All enums live in `Comfino\Enum\` with short names and no `Enum` suffix. Most are defined in `comfino/php-api-client`; `CacheItemType` is SDK-specific and lives in this package:

| Enum              | Package          | Values                                                                                         |
|-------------------|------------------|------------------------------------------------------------------------------------------------|
| `LoanType`        | `php-api-client` | `INSTALLMENTS_ZERO_PERCENT`, `CONVENIENT_INSTALLMENTS`, `PAY_LATER`, `COMPANY_INSTALLMENTS`, … |
| `OrderStatus`     | `php-api-client` | `WAITING_FOR_FILLING`, `WAITING_FOR_CONFIRMATION`, `ACCEPTED`, `REJECTED`, `RESIGN`, `PAID`, … |
| `WidgetType`      | `php-api-client` | `WIDGET_SIMPLE`, `WIDGET_MIXED`, `WIDGET_WITH_CALCULATOR`, `WIDGET_WITH_EXTENDED_CALCULATOR`   |
| `ProductListType` | `php-api-client` | `PAYWALL`, `WIDGET`                                                                            |
| `CacheItemType`   | `php-sdk`        | `ADMIN_PRODUCT_TYPES`, `ADMIN_WIDGET_TYPES`                                                    |
