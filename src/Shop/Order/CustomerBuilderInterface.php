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

/**
 * Builds a Comfino Customer DTO from a platform-specific order representation.
 */
interface CustomerBuilderInterface
{
    /**
     * Builds a Customer DTO from a platform order object.
     *
     * @param mixed $platformOrder Platform-specific order representation
     * @param string $customerIp Customer's IP address
     * @param bool $isLogged Whether the customer is logged in
     * @param bool $isRegular Whether the customer is a returning (regular) customer
     *
     * @return Customer
     */
    public function buildCustomer(mixed $platformOrder, string $customerIp, bool $isLogged, bool $isRegular): Customer;
}
