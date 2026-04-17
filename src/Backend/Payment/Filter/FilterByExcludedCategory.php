<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Payment\Filter
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\Filter;

use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Shop\Cart;
use Comfino\Shop\Product\CategoryFilter;

/**
 * Filter by excluded categories for product types - allows only products that are not in the excluded
 * product categories.
 */
class FilterByExcludedCategory implements ProductTypeFilterInterface
{
    /**
     * @param CategoryFilter $categoryFilter Category filter instance
     * @param int[][] $excludedCategoryIdsByProductType List of excluded category IDs by product type
     *                                                  ['PRODUCT_TYPE' => [excluded_category_ids]]
     */
    public function __construct(
        private readonly CategoryFilter $categoryFilter,
        private readonly array $excludedCategoryIdsByProductType
    ) {
    }

    /** @inheritDoc */
    public function getAllowedProductTypes(array $availableProductTypes, Cart $cart): array
    {
        $allowedProductTypes = [];

        foreach ($availableProductTypes as $productType) {
            if (array_key_exists($productType->getValue(), $this->excludedCategoryIdsByProductType)) {
                if (
                    $this->categoryFilter->isCartValid(
                        $cart,
                        $this->excludedCategoryIdsByProductType[$productType->getValue()]
                    )
                ) {
                    $allowedProductTypes[] = $productType;
                }
            } else {
                $allowedProductTypes[] = $productType;
            }
        }

        return $allowedProductTypes;
    }

    /** @return array<string, mixed> */
    public function getAsArray(): array
    {
        return ['excludedCategoryIdsByProductType' => $this->excludedCategoryIdsByProductType];
    }
}
