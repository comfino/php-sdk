<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Payment
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Payment;

use Comfino\Backend\Payment\ProductTypeTools;
use Comfino\Enum\LoanType;
use Comfino\Enum\UnknownLoanType;
use PHPUnit\Framework\TestCase;

final class ProductTypeToolsTest extends TestCase
{
    public function testFromApiValuesConvertsKnownTypes(): void
    {
        $result = ProductTypeTools::fromApiValues([
            'PAY_LATER',
            'INSTALLMENTS_ZERO_PERCENT',
            'CONVENIENT_INSTALLMENTS',
        ]);

        $this->assertCount(3, $result);
        $this->assertSame(LoanType::PAY_LATER, $result[0]);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $result[1]);
        $this->assertSame(LoanType::CONVENIENT_INSTALLMENTS, $result[2]);
    }

    public function testFromApiValuesWrapsUnknownTypesInUnknownLoanType(): void
    {
        $result = ProductTypeTools::fromApiValues(['FUTURE_TYPE_XYZ']);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(UnknownLoanType::class, $result[0]);
        $this->assertSame('FUTURE_TYPE_XYZ', $result[0]->getValue());
        $this->assertFalse($result[0]->isKnown());
    }

    public function testFromApiValuesMixedKnownAndUnknown(): void
    {
        $result = ProductTypeTools::fromApiValues(['PAY_LATER', 'UNKNOWN_NEW_TYPE']);

        $this->assertCount(2, $result);
        $this->assertSame(LoanType::PAY_LATER, $result[0]);
        $this->assertInstanceOf(UnknownLoanType::class, $result[1]);
    }

    public function testFromApiValuesReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], ProductTypeTools::fromApiValues([]));
    }

    public function testGetAsEnumsConvertsKnownTypes(): void
    {
        $result = ProductTypeTools::getAsEnums(['PAY_LATER', 'BLIK', 'LEASING']);

        $this->assertCount(3, $result);
        $this->assertSame(LoanType::PAY_LATER, $result[0]);
        $this->assertSame(LoanType::BLIK, $result[1]);
        $this->assertSame(LoanType::LEASING, $result[2]);
    }

    public function testGetAsEnumsSilentlyDiscardsUnknownTypes(): void
    {
        $result = ProductTypeTools::getAsEnums(['PAY_LATER', 'DOES_NOT_EXIST', 'BLIK']);

        $this->assertCount(2, $result);
        $this->assertSame(LoanType::PAY_LATER, $result[0]);
        $this->assertSame(LoanType::BLIK, $result[1]);
    }

    public function testGetAsEnumsReturnsEmptyForAllUnknownTypes(): void
    {
        $this->assertSame([], ProductTypeTools::getAsEnums(['FAKE_A', 'FAKE_B']));
    }

    public function testGetAsEnumsReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], ProductTypeTools::getAsEnums([]));
    }
}
