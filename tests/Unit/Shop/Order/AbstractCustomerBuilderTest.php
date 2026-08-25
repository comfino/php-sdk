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

use Comfino\Shop\Order\Customer\ShippingAddressData;
use PHPUnit\Framework\TestCase;

final class AbstractCustomerBuilderTest extends TestCase
{
    /**
     * Builds a concrete customer builder whose extraction hooks return the supplied fixture data and which exposes the
     * parent's protected utility helpers for direct assertion.
     *
     * @param array<string, mixed> $data
     */
    private function makeBuilder(array $data = []): ConfigurableCustomerBuilder
    {
        return new ConfigurableCustomerBuilder($data);
    }

    public function testBuildCustomerAssemblesAllScalarFields(): void
    {
        $customer = $this->makeBuilder([
            'firstName' => 'Jan',
            'lastName' => 'Kowalski',
            'email' => 'jan@example.com',
            'phone' => '+48 123-456-789',
            'taxId' => 'PL1234567',
        ])->buildCustomer(new \stdClass(), '10.0.0.1', true, false);

        $this->assertSame('Jan', $customer->getFirstName());
        $this->assertSame('Kowalski', $customer->getLastName());
        $this->assertSame('jan@example.com', $customer->getEmail());
        $this->assertSame('+48123456789', $customer->getPhoneNumber());
        $this->assertSame('10.0.0.1', $customer->getIp());
        $this->assertSame('PL1234567', $customer->getTaxId());
        $this->assertTrue($customer->isLogged());
        $this->assertFalse($customer->isRegular());
        $this->assertNull($customer->getAddress());
    }

    public function testBuildCustomerFallsBackToFullNameParsingWhenLastNameEmpty(): void
    {
        $customer = $this->makeBuilder(['firstName' => 'Jan Kowalski', 'lastName' => ''])
            ->buildCustomer(new \stdClass(), '10.0.0.1', false, false);

        $this->assertSame('Jan', $customer->getFirstName());
        $this->assertSame('Kowalski', $customer->getLastName());
    }

    public function testBuildCustomerNullsInvalidTaxId(): void
    {
        $customer = $this->makeBuilder([
            'firstName' => 'Jan',
            'lastName' => 'Kowalski',
            'taxId' => 'not-a-tax-id',
        ])->buildCustomer(new \stdClass(), '10.0.0.1', false, false);

        $this->assertNull($customer->getTaxId());
    }

    public function testBuildCustomerBuildsAddressWithParsedStreet(): void
    {
        $customer = $this->makeBuilder([
            'firstName' => 'Jan',
            'lastName' => 'Kowalski',
            'shipping' => new ShippingAddressData(
                'ul. Długa 12',
                '3',
                '00-001',
                'Warszawa',
                'PL'
            ),
        ])->buildCustomer(new \stdClass(), '10.0.0.1', false, false);

        $address = $customer->getAddress();

        $this->assertNotNull($address);
        $this->assertSame('ul. Długa', $address->getStreet());
        $this->assertSame('12', $address->getBuildingNumber());
        $this->assertSame('3', $address->getApartmentNumber());
        $this->assertSame('00-001', $address->getPostalCode());
        $this->assertSame('Warszawa', $address->getCity());
        $this->assertSame('PL', $address->getCountryCode());
    }

    public function testParseFullNameSplitsOnFirstSpace(): void
    {
        $this->assertSame(['Jan', 'Kowalski'], $this->makeBuilder()->exposeParseFullName('Jan Kowalski'));
    }

    public function testParseFullNameUsesPlaceholderWhenNoSpace(): void
    {
        $this->assertSame(['Jan', '.'], $this->makeBuilder()->exposeParseFullName('Jan'));
    }

    public function testParseFullNameReturnsEmptyTupleForEmptyInput(): void
    {
        $this->assertSame(['', ''], $this->makeBuilder()->exposeParseFullName('   '));
    }

    public function testNormalizePhoneNumberKeepsDigitsAndLeadingPlus(): void
    {
        $builder = $this->makeBuilder();

        $this->assertSame('+48123456789', $builder->exposeNormalizePhoneNumber('+48 123-456-789'));
        $this->assertSame('123456789', $builder->exposeNormalizePhoneNumber('(123) 456-789'));
    }

    public function testNormalizeTaxIdStripsNonAlphanumericAndUppercases(): void
    {
        $this->assertSame('PL1234567', $this->makeBuilder()->exposeNormalizeTaxId('pl 123-45-67'));
    }

    public function testParseStreetAddressSplitsStreetAndBuildingNumber(): void
    {
        $this->assertSame(['ul. Długa', '12', ''], $this->makeBuilder()->exposeParseStreetAddress('ul. Długa 12'));
    }

    public function testParseStreetAddressReturnsFullStreetWhenNoBuildingNumber(): void
    {
        $this->assertSame(['ul. Długa', '', ''], $this->makeBuilder()->exposeParseStreetAddress('ul. Długa'));
    }
}
