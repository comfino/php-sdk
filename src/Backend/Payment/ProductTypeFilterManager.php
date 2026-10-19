<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment;

use Comfino\Enum\LoanTypeInterface;
use Comfino\Shop\Cart;

/**
 * Manager for handling product type filtering algorithms based on cart contents.
 *
 * One instance per tenant scope, following the {@see ConfigurationManager} pattern. The filters registered here are a
 * per-tenant fact - they are built from that merchant's configured cart-value limits and excluded categories - so a
 * process-wide singleton meant the first tenant to register its filters had them applied to every other tenant's cart,
 * silently narrowing (or widening) which financial products another merchant's shoppers were offered. The default empty
 * scope preserves the original single-instance behavior for single-shop plugins.
 */
final class ProductTypeFilterManager
{
    /** @var array<string, self> One instance per tenant scope, keyed by the scope string. */
    private static array $instances = [];

    /** @var ProductTypeFilterInterface[] */
    private array $filters = [];

    /**
     * Retrieves a per-scope instance of the ProductTypeFilterManager.
     *
     * @param string $scope Tenant scope discriminator (empty string = global/default scope)
     *
     * @return self The instance bound to $scope
     */
    public static function getInstance(string $scope = ''): self
    {
        return self::$instances[$scope] ??= new self($scope);
    }

    /**
     * Returns the scopes that currently hold a cached instance.
     *
     * Diagnostics for the retention of every scope-addressed class in the SDK shares: the cache keeps the first
     * instance built for a scope and never evicts it, so a host that builds per request must release per request.
     * Asserting this is empty at the end of a unit of work turns a forgotten `reset()` into a failing test rather
     * than a slow leak.
     *
     * @return string[] Scope discriminators, in insertion order
     */
    public static function scopes(): array
    {
        return array_keys(self::$instances);
    }

    /**
     * Returns how many scopes hold a cached instance.
     */
    public static function scopeCount(): int
    {
        return count(self::$instances);
    }

    /**
     * Drops cached instances, discarding the filters registered on them.
     *
     * @param string|null $scope When given, only the instance bound to this scope is dropped; when null (default), all
     *                           cached instances (every scope) are dropped.
     */
    public static function reset(?string $scope = null): void
    {
        if ($scope === null) {
            self::$instances = [];
        } else {
            unset(self::$instances[$scope]);
        }
    }

    /**
     * Private constructor to enforce the per-scope instance pattern.
     *
     * @param string $scope Tenant scope this instance belongs to
     */
    private function __construct(private readonly string $scope = '')
    {
    }

    /**
     * Returns the tenant scope this instance is bound to.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Adds a product type filter to the manager.
     *
     * @param ProductTypeFilterInterface $filter The filter to add
     */
    public function addFilter(ProductTypeFilterInterface $filter): void
    {
        $this->filters[] = $filter;
    }

    /**
     * Returns all registered product type filters.
     *
     * @return ProductTypeFilterInterface[] Allowed product type filters
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Checks if any product type filters are active.
     *
     * @return bool True if filters are active, false otherwise
     */
    public function filtersActive(): bool
    {
        return count($this->filters) > 0;
    }

    /**
     * @param LoanTypeInterface[] $availableProductTypes All available financial product types to filter
     * @param Cart $cart Shopping cart containing product details used in filtering
     *
     * @return LoanTypeInterface[] Allowed financial product types (filtered input list)
     */
    public function getAllowedProductTypes(array $availableProductTypes, Cart $cart): array
    {
        if (empty($this->filters)) {
            return $availableProductTypes;
        }

        $allowedProductTypes = [];

        foreach ($this->filters as $filter) {
            $allowedProductTypes[] = array_map(
                static fn (LoanTypeInterface $type): string => $type->getValue(),
                $filter->getAllowedProductTypes($availableProductTypes, $cart)
            );
        }

        return array_values(array_filter(
            $availableProductTypes,
            static fn (LoanTypeInterface $type): bool => in_array($type->getValue(), array_intersect(...$allowedProductTypes), true)
        ));
    }
}
