<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order\Cart
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order\Cart;

/**
 * Shop cart item representation for the Comfino API.
 */
class CartItem implements CartItemInterface
{
    /**
     * @param ProductInterface $product Product in the shop cart
     * @param int $quantity Quantity of the product in the shop cart
     */
    public function __construct(private readonly ProductInterface $product, private readonly int $quantity)
    {
    }

    /** @inheritDoc */
    public function getProduct(): ProductInterface
    {
        return $this->product;
    }

    /** @inheritDoc */
    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
