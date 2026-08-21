<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order;

use Comfino\Enum\LoanTypeInterface;
use Comfino\Shop\Order\LoanParameters;
use PHPUnit\Framework\TestCase;

final class LoanParametersTest extends TestCase
{
    public function testGetAmountReturnsConstructedAmount(): void
    {
        $this->assertSame(50000, (new LoanParameters(50000))->getAmount());
    }

    public function testGetTermReturnsNullByDefault(): void
    {
        $this->assertNull((new LoanParameters(10000))->getTerm());
    }

    public function testGetTermReturnsConstructedTerm(): void
    {
        $this->assertSame(12, (new LoanParameters(10000, 12))->getTerm());
    }

    public function testGetTypeReturnsNullByDefault(): void
    {
        $this->assertNull((new LoanParameters(10000))->getType());
    }

    public function testGetTypeReturnsInjectedType(): void
    {
        $type = $this->createMock(LoanTypeInterface::class);

        $this->assertSame($type, (new LoanParameters(10000, null, $type))->getType());
    }

    public function testGetAllowedProductTypesReturnsNullByDefault(): void
    {
        $this->assertNull((new LoanParameters(10000))->getAllowedProductTypes());
    }

    public function testGetAllowedProductTypesReturnsInjectedArray(): void
    {
        $type1 = $this->createMock(LoanTypeInterface::class);
        $type2 = $this->createMock(LoanTypeInterface::class);
        $types = [$type1, $type2];
        $params = new LoanParameters(10000, null, null, $types);

        $this->assertSame($types, $params->getAllowedProductTypes());
    }

    public function testZeroAmountIsAccepted(): void
    {
        $this->assertSame(0, (new LoanParameters(0))->getAmount());
    }
}
