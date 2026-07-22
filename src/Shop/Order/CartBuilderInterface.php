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

use Comfino\Shop\Cart;

/**
 * Builds a Comfino Cart DTO from platform-specific cart or product representations.
 */
interface CartBuilderInterface
{
    /**
     * Builds a Cart DTO from a platform cart object.
     *
     * @param mixed $platformCart Platform-specific cart representation (e.g. Magento Quote)
     * @param int $priceModifier Optional price adjustment in grosze to add to the cart total
     *
     * @return Cart
     */
    public function buildCart(mixed $platformCart, int $priceModifier = 0): Cart;

    /**
     * Builds a single-item Cart DTO from a platform product object.
     *
     * @param mixed $platformProduct Platform-specific product representation
     *
     * @return Cart
     */
    public function buildCartFromSingleProduct(mixed $platformProduct): Cart;
}
