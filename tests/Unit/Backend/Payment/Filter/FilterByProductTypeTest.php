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

use Comfino\Backend\Payment\Filter\FilterByProductType;
use Comfino\Enum\LoanType;
use Comfino\Shop\Cart;
use PHPUnit\Framework\TestCase;

final class FilterByProductTypeTest extends TestCase
{
    public function testKeepsOnlyAllowedProductTypes(): void
    {
        $this->assertEqualsCanonicalizing(
            [LoanType::PAY_LATER, LoanType::INSTALLMENTS_ZERO_PERCENT],
            (new FilterByProductType([LoanType::PAY_LATER, LoanType::INSTALLMENTS_ZERO_PERCENT]))
                ->getAllowedProductTypes(
                    [LoanType::PAY_LATER, LoanType::CONVENIENT_INSTALLMENTS, LoanType::INSTALLMENTS_ZERO_PERCENT],
                    $this->makeCart()
                )
        );
    }

    public function testReturnsEmptyWhenNoTypesMatch(): void
    {
        $this->assertSame(
            [],
            (new FilterByProductType([LoanType::BLIK]))->getAllowedProductTypes(
                [LoanType::PAY_LATER, LoanType::CONVENIENT_INSTALLMENTS],
                $this->makeCart()
            )
        );
    }

    public function testReturnsEmptyWhenAllowedListIsEmpty(): void
    {
        $this->assertSame(
            [],
            (new FilterByProductType([]))->getAllowedProductTypes([LoanType::PAY_LATER], $this->makeCart())
        );
    }

    public function testReturnsEmptyWhenAvailableListIsEmpty(): void
    {
        $this->assertSame(
            [],
            (new FilterByProductType([LoanType::PAY_LATER]))->getAllowedProductTypes([], $this->makeCart())
        );
    }

    public function testAllTypesRetainedWhenAllAreAllowed(): void
    {
        $types = [LoanType::PAY_LATER, LoanType::INSTALLMENTS_ZERO_PERCENT, LoanType::CONVENIENT_INSTALLMENTS];

        $this->assertEqualsCanonicalizing(
            $types,
            (new FilterByProductType($types))->getAllowedProductTypes($types, $this->makeCart())
        );
    }

    public function testGetAsArrayReturnsAllowedTypes(): void
    {
        $allowedTypes = [LoanType::PAY_LATER, LoanType::BLIK];
        $arrayFilterConfiguration = (new FilterByProductType($allowedTypes))->getAsArray();

        $this->assertArrayHasKey('allowedProductTypes', $arrayFilterConfiguration);
        $this->assertSame($allowedTypes, $arrayFilterConfiguration['allowedProductTypes']);
    }

    private function makeCart(): Cart
    {
        return new Cart(
            totalValue: 5000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: []
        );
    }
}
