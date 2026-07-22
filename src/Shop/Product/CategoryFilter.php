<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Product
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Product;

use Comfino\Shop\Cart;

/**
 * Filters product categories based on exclusion lists and category tree hierarchy.
 */
class CategoryFilter
{
    /**
     * @param CategoryTree $categoryTree Category tree structure loaded from the e-commerce platform database
     */
    public function __construct(private readonly CategoryTree $categoryTree)
    {
    }

    /**
     * Checks if a category is available given a list of excluded category IDs.
     * A category is unavailable if it matches or is a descendant of any excluded category.
     *
     * @param int $categoryId Category to check
     * @param int[] $excludedCategoryIds List of excluded category IDs
     */
    public function isCategoryAvailable(int $categoryId, array $excludedCategoryIds): bool
    {
        if (in_array($categoryId, $excludedCategoryIds, true)) {
            return false;
        }

        if (($categoryNode = $this->categoryTree->getNodeById($categoryId)) === null) {
            return false;
        }

        foreach ($excludedCategoryIds as $excludedCategoryId) {
            if (($excludedCategory = $this->categoryTree->getNodeById($excludedCategoryId)) === null) {
                continue;
            }

            if ($categoryNode->isDescendantOf($excludedCategory)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if all cart products belong to available (non-excluded) categories.
     *
     * @param int[] $excludedCategoryIds List of excluded category IDs
     */
    public function isCartValid(Cart $cart, array $excludedCategoryIds): bool
    {
        if (empty($excludedCategoryIds) || empty($cart->getCartItems())) {
            return true;
        }

        $cartCategoryIds = $cart->getCartCategoryIds();

        if (count(array_intersect($cartCategoryIds, $excludedCategoryIds)) > 0) {
            return false;
        }

        foreach ($cartCategoryIds as $categoryId) {
            if (!$this->isCategoryAvailable($categoryId, $excludedCategoryIds)) {
                return false;
            }
        }

        return true;
    }
}
