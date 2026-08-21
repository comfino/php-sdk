<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order\Customer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order\Customer;

use Comfino\Shop\Order\Customer\Address;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    public function testAllGettersReturnNullByDefault(): void
    {
        $address = new Address();

        $this->assertNull($address->getStreet());
        $this->assertNull($address->getBuildingNumber());
        $this->assertNull($address->getApartmentNumber());
        $this->assertNull($address->getPostalCode());
        $this->assertNull($address->getCity());
        $this->assertNull($address->getCountryCode());
    }

    public function testGetStreetDecodesEntityAndStripsTagsAndTrims(): void
    {
        $address = new Address(street: '  <b>Ul. Kr&oacute;lewska</b>  ');

        $this->assertSame('Ul. Królewska', $address->getStreet());
    }

    public function testGetStreetReturnsNullForExplicitNull(): void
    {
        $this->assertNull((new Address(street: null))->getStreet());
    }

    public function testGetBuildingNumberDecodesAndStrips(): void
    {
        $address = new Address(buildingNumber: '  <span>12A</span>  ');

        $this->assertSame('12A', $address->getBuildingNumber());
    }

    public function testGetBuildingNumberReturnsNullForEmptyString(): void
    {
        $this->assertNull((new Address(buildingNumber: ''))->getBuildingNumber());
    }

    public function testGetApartmentNumberDecodesAndStrips(): void
    {
        $address = new Address(apartmentNumber: '  <span>5</span>  ');

        $this->assertSame('5', $address->getApartmentNumber());
    }

    public function testGetApartmentNumberReturnsNullWhenEmpty(): void
    {
        $this->assertNull((new Address(apartmentNumber: ''))->getApartmentNumber());
    }

    public function testGetPostalCodeDecodesAndStrips(): void
    {
        $address = new Address(postalCode: '  00&#8209;001  ');

        $this->assertSame('00‑001', $address->getPostalCode());
    }

    public function testGetPostalCodeReturnsNullWhenEmpty(): void
    {
        $this->assertNull((new Address(postalCode: ''))->getPostalCode());
    }

    public function testGetCityDecodesAndStrips(): void
    {
        $address = new Address(city: '  <b>Krak&oacute;w</b>  ');

        $this->assertSame('Kraków', $address->getCity());
    }

    public function testGetCityReturnsNullWhenEmpty(): void
    {
        $this->assertNull((new Address(city: ''))->getCity());
    }

    public function testGetCountryCodeDecodesAndStrips(): void
    {
        $address = new Address(countryCode: '  <i>PL</i>  ');

        $this->assertSame('PL', $address->getCountryCode());
    }

    public function testGetCountryCodeReturnsNullWhenEmpty(): void
    {
        $this->assertNull((new Address(countryCode: ''))->getCountryCode());
    }

    public function testFullAddressWithAllFields(): void
    {
        $address = new Address('Marszałkowska', '1', '2', '00-001', 'Warszawa', 'PL');

        $this->assertSame('Marszałkowska', $address->getStreet());
        $this->assertSame('1', $address->getBuildingNumber());
        $this->assertSame('2', $address->getApartmentNumber());
        $this->assertSame('00-001', $address->getPostalCode());
        $this->assertSame('Warszawa', $address->getCity());
        $this->assertSame('PL', $address->getCountryCode());
    }
}
