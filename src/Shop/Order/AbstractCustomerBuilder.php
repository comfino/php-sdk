<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

use Comfino\Shop\Order\Customer\Address;
use Comfino\Shop\Order\Customer\ShippingAddressData;

/**
 * Base customer builder that provides platform-agnostic Customer DTO assembly and utility helpers.
 *
 * Subclasses implement the abstract extraction methods to map platform-specific order representations onto the
 * Comfino Customer DTO.
 */
abstract class AbstractCustomerBuilder implements CustomerBuilderInterface
{
    /**
     * {@inheritDoc}
     *
     * Assembles a Customer DTO by delegating data extraction to the platform-specific abstract methods, normalizing
     * phone/taxId, and resolving the shipping address.
     */
    public function buildCustomer(mixed $platformOrder, string $customerIp, bool $isLogged, bool $isRegular): Customer
    {
        // Extract customer data.
        $firstName = $this->extractFirstName($platformOrder);
        $lastName = $this->extractLastName($platformOrder);

        // If last name is empty, attempt to split first name into first + last.
        if ($lastName === '') {
            [$firstName, $lastName] = $this->parseFullName($firstName);
        }

        // Normalize and validate email and phone.
        $email = $this->extractEmail($platformOrder);
        $phone = $this->normalizePhoneNumber($this->extractPhone($platformOrder));

        // Normalize and validate tax ID.
        $rawTaxId = $this->normalizeTaxId($this->extractTaxId($platformOrder));
        $taxId = preg_match('/^[A-Z]{0,3}\d{7,}$/', $rawTaxId) ? $rawTaxId : null;

        // Build shipping address from extracted data.
        $shippingData = $this->extractShippingAddress($platformOrder);
        $address = null;

        if ($shippingData !== null) {
            // Parse building number from the raw street line; apartment comes pre-resolved by the subclass.
            [$parsedStreet, $buildingNumber] = $this->parseStreetAddress($shippingData->street);

            $address = $this->buildAddress(
                $parsedStreet ?: $shippingData->street,
                $buildingNumber ?: null,
                $shippingData->apartmentNumber,
                $shippingData->postalCode,
                $shippingData->city,
                $shippingData->country
            );
        }

        return new Customer(
            $firstName,
            $lastName,
            $email,
            $phone,
            $customerIp,
            $taxId,
            $isRegular,
            $isLogged,
            $address
        );
    }

    /**
     * Extracts the customer's first name from a platform order.
     *
     * @param mixed $platformOrder Platform-specific order representation
     */
    abstract protected function extractFirstName(mixed $platformOrder): string;

    /**
     * Extracts the customer's last name from a platform order.
     *
     * @param mixed $platformOrder Platform-specific order representation
     */
    abstract protected function extractLastName(mixed $platformOrder): string;

    /**
     * Extracts the customer's email address from a platform order.
     *
     * @param mixed $platformOrder Platform-specific order representation
     */
    abstract protected function extractEmail(mixed $platformOrder): string;

    /**
     * Extracts the customer's phone number from a platform order.
     *
     * @param mixed $platformOrder Platform-specific order representation
     */
    abstract protected function extractPhone(mixed $platformOrder): string;

    /**
     * Extracts the customer's tax identification number from a platform order.
     *
     * @param mixed $platformOrder Platform-specific order representation
     *
     * @return string Raw tax ID string (may be empty)
     */
    abstract protected function extractTaxId(mixed $platformOrder): string;

    /**
     * Extracts the customer's shipping address from a platform order.
     *
     * Return null if no shipping address is available (e.g., virtual orders). The `apartment number` field may carry a
     * pre-resolved apartment number (e.g., from a second street line); when set it is used as-is and
     * {@see parseStreetAddress()} will not attempt to derive one from the street string.
     *
     * @param mixed $platformOrder Platform-specific order representation
     */
    abstract protected function extractShippingAddress(mixed $platformOrder): ?ShippingAddressData;

    /**
     * Builds an Address DTO from individual address components.
     *
     * @param string $street Street name
     * @param string|null $buildingNumber Building number (e.g. "15A")
     * @param string|null $apartmentNumber Apartment number (e.g. "3")
     * @param string|null $postalCode Postal/ZIP code
     * @param string|null $city City name
     * @param string $country ISO 3166-1 alpha-2 country code (default: 'PL')
     */
    final protected function buildAddress(
        string $street,
        ?string $buildingNumber,
        ?string $apartmentNumber,
        ?string $postalCode,
        ?string $city,
        string $country = 'PL'
    ): Address {
        return new Address($street, $buildingNumber, $apartmentNumber, $postalCode, $city, $country);
    }

    /**
     * Splits a full name string into first name and last name components.
     *
     * Splits on the first whitespace character. If no whitespace is found, returns the full name as the first name and
     * '.' as a placeholder for the last name.
     *
     * @param string $fullName Full name string
     *
     * @return array{0: string, 1: string} Tuple of [firstName, lastName]
     */
    final protected function parseFullName(string $fullName): array
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return ['', ''];
        }

        $spacePos = strpos($fullName, ' ');

        if ($spacePos === false) {
            return [$fullName, '.'];
        }

        return [substr($fullName, 0, $spacePos), trim(substr($fullName, $spacePos + 1))];
    }

    /**
     * Normalizes a tax identification number by removing non-alphanumeric characters and converting to uppercase.
     *
     * @param string $taxId Raw tax ID string
     *
     * @return string Normalized tax ID (uppercase alphanumeric only)
     */
    final protected function normalizeTaxId(string $taxId): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($taxId)) ?? '';
    }

    /**
     * Normalizes a phone number by keeping only digits and a leading plus sign.
     *
     * @param string $phone Raw phone number string
     *
     * @return string Normalized phone number
     */
    final protected function normalizePhoneNumber(string $phone): string
    {
        return preg_replace('/(?!^)\+|[^\d+]/', '', $phone) ?? '';
    }

    /**
     * Parses a full street address string into street name and building number components.
     *
     * @param string $fullStreet Full street address string
     *
     * @return array{0: string, 1: string, 2: string} Tuple of [street, buildingNumber, apartmentNumber]
     */
    protected function parseStreetAddress(string $fullStreet): array
    {
        // Split by spaces, but only if there are no digits in the first token.
        $streetTokens = preg_split('/\s+/', trim($fullStreet));

        if ($streetTokens === false || count($streetTokens) === 0) {
            // No tokens found - return empty strings.
            return [$fullStreet, '', ''];
        }

        // Find the last token that looks like a building number.
        $buildingNumber = '';
        $buildingIndex = -1;

        for ($i = count($streetTokens) - 1; $i >= 0; $i--) {
            // Check if the token looks like a building number (e.g. "15A", "1234", "1234A").
            if (preg_match('/^\d+[a-zA-Z]?$/', $streetTokens[$i])) {
                // Found a building number - return the street and the building number.
                $buildingNumber = $streetTokens[$i];
                $buildingIndex = $i;

                break;
            }
        }

        if ($buildingIndex === -1) {
            // No building number found - return the full street as the street and empty building/apartment numbers.
            return [$fullStreet, '', ''];
        }

        // If there are more tokens after the building number, return the street and the building number.
        return [implode(' ', array_slice($streetTokens, 0, $buildingIndex)), $buildingNumber, ''];
    }
}
