<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Payment\TermLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\TermLimit;

use Comfino\Shop\Order\Cart\ProductInterface;

/**
 * Decides whether a cart product has any of the given attributes (tag names, category IDs, ...).
 */
interface ProductMatcherInterface
{
    /**
     * @param array<int|string> $attributes Attributes to match against (tag names, category IDs, ...)
     */
    public function matches(ProductInterface $product, array $attributes): bool;
}
