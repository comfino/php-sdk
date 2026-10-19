<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Shop\Product
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Product;

use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\ProductInterface;

/**
 * Filters product categories against category lists and the category tree hierarchy, in both block-list (excluded
 * categories) and opt-in (required categories) modes. A listed category always covers its whole subtree.
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

    /**
     * Checks if a category is eligible given a list of required category IDs.
     * A category is eligible if it matches or is a descendant of any required category.
     *
     * @param int $categoryId Category to check
     * @param int[] $requiredCategoryIds List of required category IDs
     */
    public function isCategoryEligible(int $categoryId, array $requiredCategoryIds): bool
    {
        if (in_array($categoryId, $requiredCategoryIds, true)) {
            return true;
        }

        if (($categoryNode = $this->categoryTree->getNodeById($categoryId)) === null) {
            return false;
        }

        foreach ($requiredCategoryIds as $requiredCategoryId) {
            if (($requiredCategory = $this->categoryTree->getNodeById($requiredCategoryId)) === null) {
                continue;
            }

            if ($categoryNode->isDescendantOf($requiredCategory)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if none of the product categories is excluded. A product without category data is available.
     *
     * @param int[] $excludedCategoryIds List of excluded category IDs
     */
    public function isProductAvailable(ProductInterface $product, array $excludedCategoryIds): bool
    {
        foreach ($product->getCategoryIds() ?? [] as $categoryId) {
            if (!$this->isCategoryAvailable($categoryId, $excludedCategoryIds)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if at least one of the product categories is eligible. A product without category data is not eligible.
     *
     * @param int[] $requiredCategoryIds List of required category IDs
     */
    public function isProductEligible(ProductInterface $product, array $requiredCategoryIds): bool
    {
        foreach ($product->getCategoryIds() ?? [] as $categoryId) {
            if ($this->isCategoryEligible($categoryId, $requiredCategoryIds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opt-in list: checks if every cart product belongs to at least one required category (or its subcategory). An
     * empty list means "no requirement" and an empty cart is vacuously eligible.
     *
     * @param int[] $requiredCategoryIds List of required category IDs
     */
    public function isCartEligible(Cart $cart, array $requiredCategoryIds): bool
    {
        if (empty($requiredCategoryIds)) {
            return true;
        }

        foreach ($cart->getCartItems() as $cartItem) {
            if (!$this->isProductEligible($cartItem->getProduct(), $requiredCategoryIds)) {
                return false;
            }
        }

        return true;
    }
}
