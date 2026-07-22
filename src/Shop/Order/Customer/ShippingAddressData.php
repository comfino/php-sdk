<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order\Customer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order\Customer;

/**
 * Raw shipping address data extracted from a platform order before street parsing.
 *
 * Carries the unparsed street line (building number not yet split out) plus optional apartment number, postal code,
 * city, and country. Passed from {@see AbstractCustomerBuilder::extractShippingAddress()} to the parent's assembly
 * logic.
 */
final class ShippingAddressData
{
    /**
     * @param string $street Unparsed street line (building number not yet split out)
     * @param ?string $apartmentNumber Optional apartment number (e.g., from a second street line)
     * @param ?string $postalCode Optional postal code
     * @param ?string $city Optional city name
     * @param string $country Country code (default: 'PL'; ISO 3166-1 alpha-2)
     */
    public function __construct(
        public readonly string $street,
        public readonly ?string $apartmentNumber = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $city = null,
        public readonly string $country = 'PL'
    ) {
    }
}
