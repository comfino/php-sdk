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

use Comfino\Backend\Payment\Filter\FilterByCartValueUpperLimit;
use Comfino\Enum\LoanType;
use Comfino\Shop\Cart;
use PHPUnit\Framework\TestCase;

final class FilterByCartValueUpperLimitTest extends TestCase
{
    public function testAllowsTypeWhenCartValueMeetsUpperLimit(): void
    {
        $this->assertSame(
            [LoanType::PAY_LATER],
            (new FilterByCartValueUpperLimit([LoanType::PAY_LATER->value => 10000]))
                ->getAllowedProductTypes([LoanType::PAY_LATER], $this->makeCart(10000))
        );
    }

    public function testAllowsTypeWhenCartValueBelowUpperLimit(): void
    {
        $this->assertSame(
            [LoanType::PAY_LATER],
            (new FilterByCartValueUpperLimit([LoanType::PAY_LATER->value => 10000]))
                ->getAllowedProductTypes([LoanType::PAY_LATER], $this->makeCart(5000))
        );
    }

    public function testBlocksTypeWhenCartValueExceedsUpperLimit(): void
    {
        $this->assertSame(
            [],
            (new FilterByCartValueUpperLimit([LoanType::PAY_LATER->value => 10000]))
                ->getAllowedProductTypes([LoanType::PAY_LATER], $this->makeCart(10001))
        );
    }

    public function testPassesThroughTypeWithNoLimitDefined(): void
    {
        $this->assertSame(
            [LoanType::INSTALLMENTS_ZERO_PERCENT],
            (new FilterByCartValueUpperLimit([]))
                ->getAllowedProductTypes([LoanType::INSTALLMENTS_ZERO_PERCENT], $this->makeCart(999999))
        );
    }

    public function testMixedTypesWithAndWithoutLimits(): void
    {
        // PAY_LATER is blocked (15000 > 10000), CONVENIENT_INSTALLMENTS passes (no limit).
        $this->assertSame(
            [LoanType::CONVENIENT_INSTALLMENTS],
            (new FilterByCartValueUpperLimit([LoanType::PAY_LATER->value => 10000]))
                ->getAllowedProductTypes(
                    [LoanType::PAY_LATER, LoanType::CONVENIENT_INSTALLMENTS],
                    $this->makeCart(15000)
                )
        );
    }

    public function testReturnsEmptyWhenNoTypesAvailable(): void
    {
        $this->assertSame(
            [],
            (new FilterByCartValueUpperLimit([LoanType::PAY_LATER->value => 10000]))
                ->getAllowedProductTypes([], $this->makeCart(1))
        );
    }

    public function testGetAsArray(): void
    {
        $limits = [LoanType::PAY_LATER->value => 10000];

        $this->assertSame(
            ['cartValueLimitsByProductType' => $limits],
            (new FilterByCartValueUpperLimit($limits))->getAsArray()
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
