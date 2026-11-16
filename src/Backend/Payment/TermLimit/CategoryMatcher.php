<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Payment\TermLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\TermLimit;

use Comfino\Shop\Order\Cart\ProductInterface;
use Comfino\Shop\Product\CategoryFilter;

/**
 * Matches products belonging to any of the given categories or to their subcategories.
 */
final class CategoryMatcher implements ProductMatcherInterface
{
    public function __construct(private readonly CategoryFilter $categoryFilter)
    {
    }

    /**
     * @param array<int|string> $attributes Category IDs
     */
    public function matches(ProductInterface $product, array $attributes): bool
    {
        return $this->categoryFilter->isProductEligible($product, array_map('intval', $attributes));
    }
}
