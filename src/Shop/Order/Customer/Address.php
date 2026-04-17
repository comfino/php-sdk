<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order\Customer
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order\Customer;

/**
 * Represents a customer address for the shop.
 */
class Address implements AddressInterface
{
    /**
     * @param string|null $street Street name
     * @param string|null $buildingNumber Building number
     * @param string|null $apartmentNumber Apartment number
     * @param string|null $postalCode Postal code
     * @param string|null $city City
     * @param string|null $countryCode Country code 2-letter ISO 3166-1 alpha-2 (e.g. PL, DE, US)
     */
    public function __construct(
        private readonly ?string $street = null,
        private readonly ?string $buildingNumber = null,
        private readonly ?string $apartmentNumber = null,
        private readonly ?string $postalCode = null,
        private readonly ?string $city = null,
        private readonly ?string $countryCode = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getStreet(): ?string
    {
        return $this->street !== null ? trim(html_entity_decode(strip_tags($this->street))) : null;
    }

    /**
     * @inheritDoc
     */
    public function getBuildingNumber(): ?string
    {
        return $this->buildingNumber ? trim(html_entity_decode(strip_tags($this->buildingNumber))) : null;
    }

    /**
     * @inheritDoc
     */
    public function getApartmentNumber(): ?string
    {
        return $this->apartmentNumber ? trim(html_entity_decode(strip_tags($this->apartmentNumber))) : null;
    }

    /**
     * @inheritDoc
     */
    public function getPostalCode(): ?string
    {
        return $this->postalCode ? trim(html_entity_decode(strip_tags($this->postalCode))) : null;
    }

    /**
     * @inheritDoc
     */
    public function getCity(): ?string
    {
        return $this->city ? trim(html_entity_decode(strip_tags($this->city))) : null;
    }

    /**
     * @inheritDoc
     */
    public function getCountryCode(): ?string
    {
        return $this->countryCode ? trim(html_entity_decode(strip_tags($this->countryCode))) : null;
    }
}
