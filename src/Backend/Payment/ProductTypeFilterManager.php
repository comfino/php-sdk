<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Payment
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment;

use Comfino\Enum\LoanTypeInterface;
use Comfino\Shop\Cart;

/**
 * Manager for handling product type filtering algorithms based on cart contents.
 */
final class ProductTypeFilterManager
{
    private static ?self $instance = null;

    /** @var ProductTypeFilterInterface[] */
    private array $filters = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Resets the singleton instance (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
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
            static fn (LoanTypeInterface $type): bool => in_array(
                $type->getValue(),
                array_intersect(...$allowedProductTypes),
                true
            )
        ));
    }
}
