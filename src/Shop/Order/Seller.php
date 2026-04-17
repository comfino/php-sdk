<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

/**
 * Seller class representing a seller associated with an order.
 */
class Seller implements SellerInterface
{
    /**
     * @param string|null $taxId Seller tax ID
     */
    public function __construct(private readonly ?string $taxId)
    {
    }

    /** @inheritDoc */
    public function getTaxId(): ?string
    {
        return $this->taxId !== null ? trim(strip_tags($this->taxId)) : null;
    }
}
