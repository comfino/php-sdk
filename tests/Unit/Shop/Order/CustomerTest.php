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

use Comfino\Shop\Order\Customer;
use Comfino\Shop\Order\Customer\AddressInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    public function testGetFirstNameReturnsTrimmedStrippedValue(): void
    {
        $this->assertSame(
            'John',
            (new Customer('  <b>John</b>  ', 'Doe', 'j@e.com', '123', '1.2.3.4'))->getFirstName()
        );
    }

    public function testGetLastNameReturnsTrimmedStrippedValue(): void
    {
        $this->assertSame(
            'Doe',
            (new Customer('John', '  <i>Doe</i>  ', 'j@e.com', '123', '1.2.3.4'))->getLastName()
        );
    }

    public function testGetEmailReturnsTrimmedStrippedValue(): void
    {
        $this->assertSame(
            'john@example.com',
            (new Customer('John', 'Doe', '  john@example.com  ', '123', '1.2.3.4'))->getEmail()
        );
    }

    public function testGetPhoneNumberReturnsTrimmedStrippedValue(): void
    {
        $this->assertSame(
            '+48123456789',
            (new Customer('John', 'Doe', 'j@e.com', '  <span>+48123456789</span>  ', '1.2.3.4'))->getPhoneNumber()
        );
    }

    public function testGetIpReturnsTrimmedValue(): void
    {
        $this->assertSame('192.168.1.1', (new Customer('John', 'Doe', 'j@e.com', '123', '  192.168.1.1  '))->getIp());
    }

    public function testGetTaxIdReturnsTrimmedStrippedValueWhenSet(): void
    {
        $customer = new Customer('John', 'Doe', 'j@e.com', '123', '1.2.3.4', '  <b>1234567890</b>  ');
        $this->assertSame('1234567890', $customer->getTaxId());
    }

    public function testGetTaxIdReturnsNullWhenNotSet(): void
    {
        $this->assertNull((new Customer('John', 'Doe', 'j@e.com', '123', '1.2.3.4'))->getTaxId());
    }

    public function testIsRegularReturnsNullByDefault(): void
    {
        $this->assertNull((new Customer('John', 'Doe', 'j@e.com', '123', '1.2.3.4'))->isRegular());
    }

    public function testIsRegularReturnsTrueWhenSet(): void
    {
        $this->assertTrue((new Customer('John', 'Doe', 'j@e.com', '123', '1.2.3.4', null, true))->isRegular());
    }

    public function testIsRegularReturnsFalseWhenSetToFalse(): void
    {
        $this->assertFalse((new Customer('John', 'Doe', 'j@e.com', '123', '1.2.3.4', null, false))->isRegular());
    }

    public function testIsLoggedReturnsNullByDefault(): void
    {
        $this->assertNull((new Customer('John', 'Doe', 'j@e.com', '123', '1.2.3.4'))->isLogged());
    }

    public function testIsLoggedReturnsTrueWhenSet(): void
    {
        $this->assertTrue((new Customer('John', 'Doe', 'j@e.com', '123', '1.2.3.4', null, null, true))->isLogged());
    }

    public function testGetAddressReturnsNullByDefault(): void
    {
        $this->assertNull((new Customer('John', 'Doe', 'j@e.com', '123', '1.2.3.4'))->getAddress());
    }

    public function testGetAddressReturnsInjectedAddress(): void
    {
        $address = $this->createMock(AddressInterface::class);
        $customer = new Customer('John', 'Doe', 'j@e.com', '123', '1.2.3.4', null, null, null, $address);
        $this->assertSame($address, $customer->getAddress());
    }

    #[DataProvider('htmlSanitizationProvider')]
    public function testGetFirstNameStripsHtmlAndTrims(string $input, string $expected): void
    {
        $this->assertSame($expected, (new Customer($input, 'Doe', 'j@e.com', '123', '1.2.3.4'))->getFirstName());
    }

    /** @return array<string, array{string, string}> */
    public static function htmlSanitizationProvider(): array
    {
        return [
            'plain text' => ['Anna', 'Anna'],
            'html tags stripped' => ['<b>Anna</b>', 'Anna'],
            'leading/trailing spaces' => ['  Anna  ', 'Anna'],
            'nested tags' => ['<div><span>Anna</span></div>', 'Anna'],
            'mixed' => ['  <b> Anna </b>  ', 'Anna'],
        ];
    }
}
