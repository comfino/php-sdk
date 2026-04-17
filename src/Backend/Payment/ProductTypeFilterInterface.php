<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Payment
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment;

use Comfino\Enum\LoanTypeInterface;
use Comfino\Shop\Cart;

/**
 * Interface for filtering algorithms of allowed product types based on cart contents.
 */
interface ProductTypeFilterInterface
{
    /**
     * @param LoanTypeInterface[] $availableProductTypes All available financial product types to filter
     * @param Cart $cart Shopping cart containing product details used in filtering
     *
     * @return LoanTypeInterface[] Allowed financial product types (filtered input list)
     */
    public function getAllowedProductTypes(array $availableProductTypes, Cart $cart): array;

    /**
     * Returns the filter's configuration as an associative array.
     *
     * @return array<string, mixed> Filter configuration data
     */
    public function getAsArray(): array;
}
