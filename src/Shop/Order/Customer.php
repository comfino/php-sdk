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

use Comfino\Shop\Order\Customer\AddressInterface;

/**
 * Represents a customer entity for the Comfino API.
 */
class Customer implements CustomerInterface
{
    /**
     * @param string $firstName First name of the customer
     * @param string $lastName Last name of the customer
     * @param string $email E-mail address of the customer
     * @param string $phoneNumber Mobile phone number of the customer
     * @param string $ip IP address of the customer
     * @param string|null $taxId Tax identification number of the customer
     * @param bool|null $isRegular Indicates if the customer is regular
     * @param bool|null $isLogged Indicates if the customer is logged in
     * @param AddressInterface|null $address Shipping address of the customer
     */
    public function __construct(
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $email,
        private readonly string $phoneNumber,
        private readonly string $ip,
        private readonly ?string $taxId = null,
        private readonly ?bool $isRegular = null,
        private readonly ?bool $isLogged = null,
        private readonly ?AddressInterface $address = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getFirstName(): string
    {
        return trim(strip_tags($this->firstName));
    }

    /**
     * @inheritDoc
     */
    public function getLastName(): string
    {
        return trim(strip_tags($this->lastName));
    }

    /**
     * @inheritDoc
     */
    public function getEmail(): string
    {
        return trim(strip_tags($this->email));
    }

    /**
     * @inheritDoc
     */
    public function getPhoneNumber(): string
    {
        return trim(strip_tags($this->phoneNumber));
    }

    /**
     * @inheritDoc
     */
    public function getIp(): string
    {
        return trim($this->ip);
    }

    /**
     * @inheritDoc
     */
    public function getTaxId(): ?string
    {
        return $this->taxId !== null ? trim(strip_tags($this->taxId)) : null;
    }

    /**
     * @inheritDoc
     */
    public function isRegular(): ?bool
    {
        return $this->isRegular;
    }

    /**
     * @inheritDoc
     */
    public function isLogged(): ?bool
    {
        return $this->isLogged;
    }

    /**
     * @inheritDoc
     */
    public function getAddress(): ?AddressInterface
    {
        return $this->address;
    }
}
