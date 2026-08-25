<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Frontend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Frontend;

use Comfino\Frontend\PaywallCartSerializer;
use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\Product;
use PHPUnit\Framework\TestCase;

final class PaywallCartSerializerTest extends TestCase
{
    public function testReturnsNullForEmptyCart(): void
    {
        $cart = new Cart(0, null, null, 0, null, null, null, []);

        self::assertNull(PaywallCartSerializer::serialize($cart));
    }

    public function testSerializesFullCartShape(): void
    {
        $product = new Product(
            name: 'Test product',
            price: 12300,
            id: 'SKU-1',
            category: 'Electronics',
            ean: null,
            photoUrl: null,
            categoryIds: [5, 7],
            netPrice: 10000,
            taxRate: 23,
            taxValue: 2300
        );

        $cart = new Cart(
            totalValue: 13300,
            totalNetValue: 11000,
            totalTaxValue: 2300,
            deliveryCost: 1000,
            deliveryNetCost: 813,
            deliveryTaxRate: 23,
            deliveryTaxValue: 187,
            cartItems: [new CartItem($product, 2)]
        );

        self::assertSame(
            [
                'totalAmount' => 13300,
                'deliveryCost' => 1000,
                'deliveryNetCost' => 813,
                'deliveryCostVatRate' => 23,
                'deliveryCostVatAmount' => 187,
                'products' => [
                    [
                        'name' => 'Test product',
                        'quantity' => 2,
                        'price' => 12300,
                        'netPrice' => 10000,
                        'vatRate' => 23,
                        'vatAmount' => 2300,
                        'category' => 'Electronics',
                    ],
                ],
            ],
            PaywallCartSerializer::serialize($cart)
        );
    }

    public function testNullNumericFieldsDefaultToZeroAndNetPriceFallsBackToPrice(): void
    {
        // Product with no net price / tax data; cart with no delivery net / tax data.
        $product = new Product(name: 'No-tax product', price: 5000);

        $cart = new Cart(
            totalValue: 5000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [new CartItem($product, 1)]
        );

        $result = PaywallCartSerializer::serialize($cart);

        self::assertNotNull($result);
        self::assertSame(0, $result['deliveryNetCost']);
        self::assertSame(0, $result['deliveryCostVatRate']);
        self::assertSame(0, $result['deliveryCostVatAmount']);
        self::assertSame(5000, $result['products'][0]['netPrice'], 'netPrice falls back to price when null');
        self::assertSame(0, $result['products'][0]['vatRate']);
        self::assertSame(0, $result['products'][0]['vatAmount']);
        self::assertSame('', $result['products'][0]['category']);
    }
}
