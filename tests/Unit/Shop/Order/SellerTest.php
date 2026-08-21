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

use Comfino\Shop\Order\Seller;
use PHPUnit\Framework\TestCase;

final class SellerTest extends TestCase
{
    public function testGetTaxIdReturnsNullWhenNullPassed(): void
    {
        $this->assertNull((new Seller(null))->getTaxId());
    }

    public function testGetTaxIdStripsTagsAndTrims(): void
    {
        $this->assertSame('1234567890', (new Seller('  <b>1234567890</b>  '))->getTaxId());
    }

    public function testGetTaxIdReturnsPlainValue(): void
    {
        $this->assertSame('9876543210', (new Seller('9876543210'))->getTaxId());
    }

    public function testGetTaxIdStripsNestedTags(): void
    {
        $this->assertSame('PL1234567890', (new Seller('<div><span>PL1234567890</span></div>'))->getTaxId());
    }
}
