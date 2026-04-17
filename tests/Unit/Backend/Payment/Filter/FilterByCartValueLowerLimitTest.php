<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Payment\Filter
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Payment\Filter;

use Comfino\Backend\Payment\Filter\FilterByCartValueLowerLimit;
use Comfino\Enum\LoanType;
use Comfino\Shop\Cart;
use PHPUnit\Framework\TestCase;

final class FilterByCartValueLowerLimitTest extends TestCase
{
    public function testAllowsTypeWhenCartValueMeetsLowerLimit(): void
    {
        $this->assertSame(
            [LoanType::PAY_LATER],
            (new FilterByCartValueLowerLimit([LoanType::PAY_LATER->value => 5000]))
                ->getAllowedProductTypes([LoanType::PAY_LATER], $this->makeCart(5000))
        );
    }

    public function testAllowsTypeWhenCartValueExceedsLowerLimit(): void
    {
        $this->assertSame(
            [LoanType::PAY_LATER],
            (new FilterByCartValueLowerLimit([LoanType::PAY_LATER->value => 5000]))
                ->getAllowedProductTypes([LoanType::PAY_LATER], $this->makeCart(9999))
        );
    }

    public function testBlocksTypeWhenCartValueBelowLowerLimit(): void
    {
        $this->assertSame(
            [],
            (new FilterByCartValueLowerLimit([LoanType::PAY_LATER->value => 5000,]))
                ->getAllowedProductTypes([LoanType::PAY_LATER], $this->makeCart(4999))
        );
    }

    public function testPassesThroughTypeWithNoLimitDefined(): void
    {
        $this->assertSame(
            [LoanType::INSTALLMENTS_ZERO_PERCENT],
            (new FilterByCartValueLowerLimit([]))
                ->getAllowedProductTypes([LoanType::INSTALLMENTS_ZERO_PERCENT], $this->makeCart(100))
        );
    }

    public function testMixedTypesWithAndWithoutLimits(): void
    {
        // PAY_LATER is blocked (3000 < 5000), INSTALLMENTS_ZERO_PERCENT passes through (no limit).
        $this->assertSame(
            [LoanType::INSTALLMENTS_ZERO_PERCENT],
            (new FilterByCartValueLowerLimit([LoanType::PAY_LATER->value => 5000]))
                ->getAllowedProductTypes(
                    [LoanType::PAY_LATER, LoanType::INSTALLMENTS_ZERO_PERCENT],
                    $this->makeCart(3000)
                )
        );
    }

    public function testReturnsEmptyWhenNoTypesAvailable(): void
    {
        $this->assertSame(
            [],
            (new FilterByCartValueLowerLimit([LoanType::PAY_LATER->value => 1000]))
                ->getAllowedProductTypes([], $this->makeCart(9999))
        );
    }

    public function testGetAsArray(): void
    {
        $limits = [LoanType::PAY_LATER->value => 5000];

        $this->assertSame(
            ['cartValueLimitsByProductType' => $limits],
            (new FilterByCartValueLowerLimit($limits))->getAsArray()
        );
    }

    private function makeCart(int $totalValue): Cart
    {
        return new Cart(
            totalValue: $totalValue,
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
