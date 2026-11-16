<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Payment\Filter
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\Filter;

use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Shop\Cart;
use Comfino\Shop\Product\CategoryFilter;

/**
 * Filter by required categories for product types (opt-in list) - allows a category-controlled product type only when
 * every cart product belongs to at least one of the categories required for that type (or to their subcategories).
 * Types without a category list are not restricted.
 */
class FilterByRequiredCategory implements ProductTypeFilterInterface
{
    /**
     * @param CategoryFilter $categoryFilter Category filter instance
     * @param int[][] $requiredCategoryIdsByProductType List of required category IDs by product type ['PRODUCT_TYPE' => [required_category_ids]]
     */
    public function __construct(private readonly CategoryFilter $categoryFilter, private readonly array $requiredCategoryIdsByProductType)
    {
    }

    /** @inheritDoc */
    public function getAllowedProductTypes(array $availableProductTypes, Cart $cart): array
    {
        $allowedProductTypes = [];

        foreach ($availableProductTypes as $productType) {
            if (array_key_exists($productType->getValue(), $this->requiredCategoryIdsByProductType)) {
                if ($this->categoryFilter->isCartEligible($cart, $this->requiredCategoryIdsByProductType[$productType->getValue()])) {
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
        return ['requiredCategoryIdsByProductType' => $this->requiredCategoryIdsByProductType];
    }
}
