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

use Comfino\Shop\Order\Customer\ShippingAddressData;
use PHPUnit\Framework\TestCase;

final class ShippingAddressDataTest extends TestCase
{
    public function testConstructorAssignsAllFields(): void
    {
        $data = new ShippingAddressData('Marszałkowska 1', '2A', '00-001', 'Warszawa', 'PL');

        $this->assertSame('Marszałkowska 1', $data->street);
        $this->assertSame('2A', $data->apartmentNumber);
        $this->assertSame('00-001', $data->postalCode);
        $this->assertSame('Warszawa', $data->city);
        $this->assertSame('PL', $data->country);
    }

    public function testOptionalFieldsDefaultToNull(): void
    {
        $data = new ShippingAddressData('Nowa 5');

        $this->assertSame('Nowa 5', $data->street);
        $this->assertNull($data->apartmentNumber);
        $this->assertNull($data->postalCode);
        $this->assertNull($data->city);
        $this->assertSame('PL', $data->country);
    }

    public function testCountryDefaultsToPoland(): void
    {
        $this->assertSame('PL', (new ShippingAddressData('Jakaś 1'))->country);
    }

    public function testCustomCountryIsPreserved(): void
    {
        $data = new ShippingAddressData('Hauptstraße 1', null, null, null, 'DE');

        $this->assertSame('DE', $data->country);
    }
}
