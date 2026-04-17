# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-04-17

### Added
- Initial release of the Comfino Payment Gateway PHP Backend SDK.
- `ConfigurationManager` singleton for centralized API credentials and plugin settings storage via a pluggable `StorageAdapterInterface`.
- `CacheManager` singleton wrapping a PSR-6 `CacheItemPoolInterface` with optional PSR-6 cache-tag interop support.
- `DebugLogger` and `ErrorLogger` singletons backed by a PSR-3 `LoggerInterface`; `SensitiveDataProcessor` masks API keys and personal data before writing to logs.
- `LoggerFactory` for creating preconfigured logger instances.
- `ProductTypeFilterManager` with a composable filter chain: `FilterByProductType`, `FilterByCartValueLowerLimit`, `FilterByCartValueUpperLimit`, `FilterByExcludedCategory`.
- `CategoryTree` with lazy construction via `BuildStrategyInterface` and O(1) ID-indexed lookups; `CategoryFilter` for ancestor/descendant exclusion checks.
- `WebhookManager` for routing authenticated incoming webhook requests to registered `WebhookEndpointInterface` implementations with optional rate-limiting and replay-protection hooks.
- Built-in webhook endpoint implementations: `StatusNotification`, `Configuration`, `CacheInvalidate`.
- `ApiClientFactory`, `OrderFactory`, and `WebhookManagerFactory` for consistent object construction in e-commerce plugin integrations.
- `WidgetInitScript` and `WidgetInitScriptHelper` for building the Comfino widget initialization script tag.
- `FrontendHelper` with logo URL and paywall auth hash helpers for frontend rendering.
- Concrete shop domain model implementations: `Order`, `Cart`, `CartItem`, `Product`, `Customer`, `Address`, `LoanParameters`, `Seller`.
- `Cart::getItemsCount()` and `Cart::getTotalItemsCount()` for distinct item count and total quantity respectively.
- `StatusManager` singleton mapping platform-specific order status adapters to Comfino order statuses.
- `FileUtils` helper for safe file read/write operations within plugin contexts.
- `CacheItemType` enum for typed cache key namespacing.
- Depends on `comfino/php-api-client ^2.0` for all Comfino REST API communication.
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

[Unreleased]: https://github.com/comfino/php-sdk/compare/1.0.0...HEAD
[1.0.0]: https://github.com/comfino/php-sdk/releases/tag/1.0.0
