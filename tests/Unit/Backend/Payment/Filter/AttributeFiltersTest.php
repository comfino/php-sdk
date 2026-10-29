<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Payment\Filter
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Payment\Filter;

use Comfino\Backend\Payment\Filter\FilterByExcludedTag;
use Comfino\Backend\Payment\Filter\FilterByRequiredCategory;
use Comfino\Backend\Payment\Filter\FilterByRequiredTag;
use Comfino\Enum\LoanType;
use Comfino\Shop\Product\CategoryFilter;
use Comfino\Tests\Unit\Support\ProductFilteringFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Tag (opt-in and block list) and required-category product type filters.
 */
final class AttributeFiltersTest extends TestCase
{
    use ProductFilteringFixtures;

    private const ZERO = LoanType::INSTALLMENTS_ZERO_PERCENT;
    private const AVAILABLE = [LoanType::INSTALLMENTS_ZERO_PERCENT, LoanType::CONVENIENT_INSTALLMENTS, LoanType::PAY_LATER];

    public function testRequiredTagAllowsTypeOnlyWhenAllProductsAreTagged(): void
    {
        $filter = new FilterByRequiredTag([self::ZERO->value => ['raty0-12', 'raty0-24']]);

        $this->assertSame(
            self::AVAILABLE,
            $filter->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('1', ['raty0-12']), self::product('2', ['raty0-24'])))
        );
        $this->assertSame(
            [LoanType::CONVENIENT_INSTALLMENTS, LoanType::PAY_LATER],
            $filter->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('1', ['raty0-12']), self::product('2')))
        );
        $this->assertSame(['requiredTagsByProductType' => [self::ZERO->value => ['raty0-12', 'raty0-24']], 'caseInsensitive' => false], $filter->getAsArray());
    }

    public function testExcludedTagBlocksTypeWhenAnyProductIsTagged(): void
    {
        $filter = new FilterByExcludedTag([LoanType::PAY_LATER->value => ['no-paylater']]);

        $this->assertSame(
            [self::ZERO, LoanType::CONVENIENT_INSTALLMENTS],
            $filter->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('1'), self::product('2', ['no-paylater'])))
        );
        $this->assertSame(self::AVAILABLE, $filter->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('1', ['promo']))));
        $this->assertSame(['excludedTagsByProductType' => [LoanType::PAY_LATER->value => ['no-paylater']], 'caseInsensitive' => false], $filter->getAsArray());
    }

    public function testTagFiltersHonorCaseInsensitiveFlag(): void
    {
        $cart = self::cart(self::product('1', ['RATY0-12']), self::product('2', ['No-PayLater']));

        $this->assertSame(
            [LoanType::CONVENIENT_INSTALLMENTS, LoanType::PAY_LATER],
            (new FilterByRequiredTag([self::ZERO->value => ['raty0-12']]))
                ->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('1', ['RATY0-12'])))
        );
        $this->assertSame(
            self::AVAILABLE,
            (new FilterByRequiredTag([self::ZERO->value => ['raty0-12']], caseInsensitive: true))
                ->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('1', ['RATY0-12'])))
        );
        $this->assertSame(
            [self::ZERO, LoanType::CONVENIENT_INSTALLMENTS],
            (new FilterByExcludedTag([LoanType::PAY_LATER->value => ['no-paylater']], caseInsensitive: true))
                ->getAllowedProductTypes(self::AVAILABLE, $cart)
        );
        $this->assertSame(
            ['excludedTagsByProductType' => [LoanType::PAY_LATER->value => ['no-paylater']], 'caseInsensitive' => true],
            (new FilterByExcludedTag([LoanType::PAY_LATER->value => ['no-paylater']], caseInsensitive: true))->getAsArray()
        );
    }

    public function testRequiredCategoryAllowsTypeOnlyWhenAllProductsAreInListedSubtrees(): void
    {
        $filter = new FilterByRequiredCategory(new CategoryFilter(self::categoryTree()), [self::ZERO->value => [1]]);

        $this->assertSame(
            self::AVAILABLE,
            $filter->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('1', categoryIds: [100]), self::product('2', categoryIds: [11])))
        );
        $this->assertSame(
            [LoanType::CONVENIENT_INSTALLMENTS, LoanType::PAY_LATER],
            $filter->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('1', categoryIds: [100]), self::product('2', categoryIds: [2])))
        );
        $this->assertSame(['requiredCategoryIdsByProductType' => [self::ZERO->value => [1]]], $filter->getAsArray());
    }
}
