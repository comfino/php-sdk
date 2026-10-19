<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Product
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Product;

use Comfino\Shop\Product\CategoryFilter;
use Comfino\Tests\Unit\Support\ProductFilteringFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in (required categories) and product-level checks of CategoryFilter on a real category tree.
 */
final class CategoryFilterEligibilityTest extends TestCase
{
    use ProductFilteringFixtures;

    public function testCategoryIsEligibleWhenListedOrDescendantOfListed(): void
    {
        $filter = new CategoryFilter(self::categoryTree());

        $this->assertTrue($filter->isCategoryEligible(10, [10]));
        $this->assertTrue($filter->isCategoryEligible(100, [1]));
        $this->assertFalse($filter->isCategoryEligible(1, [10]));
        $this->assertFalse($filter->isCategoryEligible(2, [1]));
        $this->assertFalse($filter->isCategoryEligible(999, [1]));
    }

    public function testProductEligibilityNeedsAtLeastOneEligibleCategory(): void
    {
        $filter = new CategoryFilter(self::categoryTree());

        $this->assertTrue($filter->isProductEligible(self::product('1', categoryIds: [2, 100]), [10]));
        $this->assertFalse($filter->isProductEligible(self::product('2', categoryIds: [2]), [10]));
        $this->assertFalse($filter->isProductEligible(self::product('3'), [10]));
    }

    public function testProductAvailabilityFailsWhenAnyCategoryIsExcluded(): void
    {
        $filter = new CategoryFilter(self::categoryTree());

        $this->assertFalse($filter->isProductAvailable(self::product('1', categoryIds: [2, 100]), [1]));
        $this->assertTrue($filter->isProductAvailable(self::product('2', categoryIds: [2]), [1]));
        $this->assertTrue($filter->isProductAvailable(self::product('3'), [1]));
    }

    public function testCartEligibilityRequiresEveryProduct(): void
    {
        $filter = new CategoryFilter(self::categoryTree());

        $this->assertTrue($filter->isCartEligible(self::cart(self::product('1', categoryIds: [100]), self::product('2', categoryIds: [11])), [1]));
        $this->assertFalse($filter->isCartEligible(self::cart(self::product('1', categoryIds: [100]), self::product('2', categoryIds: [2])), [1]));
        $this->assertTrue($filter->isCartEligible(self::cart(self::product('1', categoryIds: [2])), []));
        $this->assertTrue($filter->isCartEligible(self::cart(), [1]));
    }
}
