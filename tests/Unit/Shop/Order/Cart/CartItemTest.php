<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order\Cart
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order\Cart;

use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\ProductInterface;
use PHPUnit\Framework\TestCase;

final class CartItemTest extends TestCase
{
    public function testGetProductReturnsConstructedProduct(): void
    {
        $product = $this->createMock(ProductInterface::class);

        $this->assertSame($product, (new CartItem($product, 2))->getProduct());
    }

    public function testGetQuantityReturnsConstructedQuantity(): void
    {
        $this->assertSame(5, (new CartItem($this->createMock(ProductInterface::class), 5))->getQuantity());
    }

    public function testQuantityOfOne(): void
    {
        $this->assertSame(1, (new CartItem($this->createMock(ProductInterface::class), 1))->getQuantity());
    }
}
