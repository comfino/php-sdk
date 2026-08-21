<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Payment\Filter
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Payment\Filter;

use Comfino\Backend\Payment\Filter\FilterByExcludedCategory;
use Comfino\Enum\LoanType;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItemInterface;
use Comfino\Shop\Order\Cart\ProductInterface;
use Comfino\Shop\Product\CategoryFilter;
use PHPUnit\Framework\TestCase;

final class FilterByExcludedCategoryTest extends TestCase
{
    public function testAllowsTypeWhenCartIsValid(): void
    {
        $this->assertSame(
            [LoanType::PAY_LATER],
            (new FilterByExcludedCategory($this->makeCategoryFilter(true), [LoanType::PAY_LATER->value => [10, 20]]))
                ->getAllowedProductTypes([LoanType::PAY_LATER], $this->makeCart())
        );
    }

    public function testBlocksTypeWhenCartIsInvalid(): void
    {
        $this->assertSame(
            [],
            (new FilterByExcludedCategory($this->makeCategoryFilter(false), [LoanType::PAY_LATER->value => [1]]))
                ->getAllowedProductTypes([LoanType::PAY_LATER], $this->makeCart())
        );
    }

    public function testPassesThroughTypeWithNoExclusionsDefined(): void
    {
        $categoryFilter = $this->createMock(CategoryFilter::class);
        $categoryFilter->expects($this->never())->method('isCartValid');

        $this->assertSame(
            [LoanType::INSTALLMENTS_ZERO_PERCENT],
            (new FilterByExcludedCategory($categoryFilter, []))
                ->getAllowedProductTypes([LoanType::INSTALLMENTS_ZERO_PERCENT], $this->makeCart())
        );
    }

    public function testMixedTypesWithAndWithoutExclusions(): void
    {
        $categoryFilter = $this->createMock(CategoryFilter::class);
        $categoryFilter->method('isCartValid')->willReturn(false);

        // PAY_LATER is blocked (isCartValid returns false), CONVENIENT_INSTALLMENTS passes (no exclusion defined).
        $this->assertSame(
            [LoanType::CONVENIENT_INSTALLMENTS],
            (new FilterByExcludedCategory($categoryFilter, [LoanType::PAY_LATER->value => [1]]))
                ->getAllowedProductTypes([LoanType::PAY_LATER, LoanType::CONVENIENT_INSTALLMENTS], $this->makeCart())
        );
    }

    public function testGetAsArray(): void
    {
        $exclusions = [LoanType::PAY_LATER->value => [1, 2, 3]];

        $this->assertSame(
            ['excludedCategoryIdsByProductType' => $exclusions],
            (new FilterByExcludedCategory($this->makeCategoryFilter(true), $exclusions))->getAsArray()
        );
    }

    private function makeCategoryFilter(bool $isCartValid): CategoryFilter
    {
        $filter = $this->createMock(CategoryFilter::class);
        $filter->method('isCartValid')->willReturn($isCartValid);

        return $filter;
    }

    private function makeCart(): Cart
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCategoryIds')->willReturn([1]);

        $item = $this->createMock(CartItemInterface::class);
        $item->method('getProduct')->willReturn($product);

        return new Cart(
            totalValue: 5000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [$item]
        );
    }
}
