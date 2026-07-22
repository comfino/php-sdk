<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order;

use Comfino\Shop\Order\Cart;
use Comfino\Shop\Order\Cart\CartItemInterface;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    public function testGettersReturnConstructedValues(): void
    {
        $item = $this->makeItem();
        $cart = new Cart(
            items: [$item],
            totalAmount: 12000,
            deliveryCost: 600,
            deliveryNetCost: 500,
            deliveryCostTaxRate: 23,
            deliveryCostTaxValue: 100,
            category: 'Electronics'
        );

        $this->assertSame([$item], $cart->getItems());
        $this->assertSame(12000, $cart->getTotalAmount());
        $this->assertSame(600, $cart->getDeliveryCost());
        $this->assertSame(500, $cart->getDeliveryNetCost());
        $this->assertSame(23, $cart->getDeliveryCostTaxRate());
        $this->assertSame(100, $cart->getDeliveryCostTaxValue());
        $this->assertSame('Electronics', $cart->getCategory());
    }

    public function testNullableFieldsDefaultToNull(): void
    {
        $cart = new Cart(items: [], totalAmount: 5000);

        $this->assertNull($cart->getDeliveryCost());
        $this->assertNull($cart->getDeliveryNetCost());
        $this->assertNull($cart->getDeliveryCostTaxRate());
        $this->assertNull($cart->getDeliveryCostTaxValue());
        $this->assertNull($cart->getCategory());
    }

    public function testEmptyItems(): void
    {
        $cart = new Cart(items: [], totalAmount: 0);

        $this->assertSame([], $cart->getItems());
        $this->assertSame(0, $cart->getTotalAmount());
    }

    public function testMultipleItems(): void
    {
        $items = [$this->makeItem(), $this->makeItem(), $this->makeItem()];
        $cart = new Cart(items: $items, totalAmount: 30000);

        $this->assertCount(3, $cart->getItems());
        $this->assertSame($items, $cart->getItems());
    }

    private function makeItem(): CartItemInterface
    {
        return $this->createMock(CartItemInterface::class);
    }
}
