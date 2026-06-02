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
 * Adapter interface for updating order status in the shop.
 */
interface StatusAdapterInterface
{
    /**
     * Updates order status in the shop.
     *
     * @param string $orderId Shop internal order ID
     * @param string $status New order status
     */
    public function setStatus(string $orderId, string $status): void;
}
