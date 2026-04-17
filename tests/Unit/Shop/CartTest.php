<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop;

use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItemInterface;
use Comfino\Shop\Order\Cart\ProductInterface;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    public function testGettersReturnConstructedValues(): void
    {
        $cart = new Cart(
            totalValue: 10000,
            totalNetValue: 8000,
            totalTaxValue: 2000,
            deliveryCost: 500,
            deliveryNetCost: 400,
            deliveryTaxRate: 23,
            deliveryTaxValue: 115,
            cartItems: [$this->createMockCartItem($this->createMockProduct([1]))]
        );

        $this->assertSame(10000, $cart->getTotalValue());
        $this->assertSame(8000, $cart->getTotalNetValue());
        $this->assertSame(2000, $cart->getTotalTaxValue());
        $this->assertSame(500, $cart->getDeliveryCost());
        $this->assertSame(400, $cart->getDeliveryNetCost());
        $this->assertSame(23, $cart->getDeliveryTaxRate());
        $this->assertSame(115, $cart->getDeliveryTaxValue());
        $this->assertCount(1, $cart->getCartItems());
    }

    public function testNullableGettersReturnNull(): void
    {
        $cart = new Cart(
            totalValue: 5000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: []
        );

        $this->assertNull($cart->getTotalNetValue());
        $this->assertNull($cart->getTotalTaxValue());
        $this->assertNull($cart->getDeliveryNetCost());
        $this->assertNull($cart->getDeliveryTaxRate());
        $this->assertNull($cart->getDeliveryTaxValue());
        $this->assertSame([], $cart->getCartItems());
    }

    public function testGetItemsCountReturnsNumberOfCartItemEntries(): void
    {
        $cart = new Cart(
            totalValue: 10000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [
                $this->createMockCartItem($this->createMockProduct(), 3),
                $this->createMockCartItem($this->createMockProduct(), 2),
            ]
        );

        $this->assertSame(2, $cart->getItemsCount());
    }

    public function testGetItemsCountReturnsZeroForEmptyCart(): void
    {
        $cart = new Cart(
            totalValue: 0,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: []
        );

        $this->assertSame(0, $cart->getItemsCount());
    }

    public function testGetTotalItemsCountReturnsSumOfQuantities(): void
    {
        $cart = new Cart(
            totalValue: 10000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [
                $this->createMockCartItem($this->createMockProduct(), 3),
                $this->createMockCartItem($this->createMockProduct(), 2),
                $this->createMockCartItem($this->createMockProduct(), 5),
            ]
        );

        $this->assertSame(10, $cart->getTotalItemsCount());
    }

    public function testGetTotalItemsCountReturnsZeroForEmptyCart(): void
    {
        $cart = new Cart(
            totalValue: 0,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: []
        );

        $this->assertSame(0, $cart->getTotalItemsCount());
    }

    public function testGetCartCategoryIdsExtractsCategoryIdsFromItems(): void
    {
        $item1 = $this->createMockCartItem($this->createMockProduct([1, 2]));
        $item2 = $this->createMockCartItem($this->createMockProduct([3]));

        $cart = new Cart(
            totalValue: 10000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [$item1, $item2]
        );

        $this->assertEqualsCanonicalizing([1, 2, 3], $cart->getCartCategoryIds());
    }

    public function testGetCartCategoryIdsDeduplicates(): void
    {
        $item1 = $this->createMockCartItem($this->createMockProduct([1, 2]));
        $item2 = $this->createMockCartItem($this->createMockProduct([2, 3]));

        $cart = new Cart(
            totalValue: 10000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [$item1, $item2]
        );

        $categoryIds = $cart->getCartCategoryIds();

        $this->assertCount(3, $categoryIds);
        $this->assertEqualsCanonicalizing([1, 2, 3], $categoryIds);
    }

    public function testGetCartCategoryIdsIsCached(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->expects($this->once())->method('getCategoryIds')->willReturn([10, 20]);

        $cart = new Cart(
            totalValue: 10000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [$this->createMockCartItem($product)]
        );

        // Call twice - getCategoryIds() on the product should only be called once due to caching.
        $cart->getCartCategoryIds();
        $cart->getCartCategoryIds();
    }

    public function testGetCartCategoryIdsSkipsItemsWithNullCategoryIds(): void
    {
        $item1 = $this->createMockCartItem($this->createMockProduct());
        $item2 = $this->createMockCartItem($this->createMockProduct([5]));

        $cart = new Cart(
            totalValue: 10000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [$item1, $item2]
        );

        $this->assertSame([5], $cart->getCartCategoryIds());
    }

    public function testGetCartCategoryIdsReturnsEmptyArrayForEmptyCart(): void
    {
        $cart = new Cart(
            totalValue: 0,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: []
        );

        $this->assertSame([], $cart->getCartCategoryIds());
    }

    public function testGetAsArrayIncludesNullsByDefault(): void
    {
        $cart = new Cart(
            totalValue: 9000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 500,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [$this->createMockCartItem($this->createMockProduct(), 2)]
        );

        $cartArray = $cart->getAsArray();

        $this->assertSame(9000, $cartArray['totalAmount']);
        $this->assertSame(500, $cartArray['deliveryCost']);
        $this->assertArrayHasKey('deliveryNetCost', $cartArray);
        $this->assertArrayHasKey('deliveryCostVatRate', $cartArray);
        $this->assertArrayHasKey('deliveryCostVatAmount', $cartArray);
        $this->assertNull($cartArray['deliveryNetCost']);
        $this->assertNull($cartArray['deliveryCostVatRate']);
        $this->assertNull($cartArray['deliveryCostVatAmount']);
        $this->assertCount(1, $cartArray['products']);
        $this->assertArrayHasKey('netPrice', $cartArray['products'][0]);
        $this->assertNull($cartArray['products'][0]['netPrice']);
    }

    public function testGetAsArrayWithoutNullsFiltersNullValues(): void
    {
        $cart = new Cart(
            totalValue: 7000,
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 300,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [$this->createMockCartItem($this->createMockProduct(null), 1)]
        );

        $cartArray = $cart->getAsArray(false);

        $this->assertSame(7000, $cartArray['totalAmount']);
        $this->assertSame(300, $cartArray['deliveryCost']);
        $this->assertArrayNotHasKey('deliveryNetCost', $cartArray);
        $this->assertArrayNotHasKey('deliveryCostVatRate', $cartArray);
        $this->assertArrayNotHasKey('deliveryCostVatAmount', $cartArray);
        $this->assertCount(1, $cartArray['products']);
        $this->assertArrayNotHasKey('netPrice', $cartArray['products'][0]);
        $this->assertArrayNotHasKey('vatRate', $cartArray['products'][0]);
    }

    public function testGetAsArrayProductFieldsArePopulated(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getName')->willReturn('Widget');
        $product->method('getPrice')->willReturn(2500);
        $product->method('getNetPrice')->willReturn(2000);
        $product->method('getTaxRate')->willReturn(25);
        $product->method('getTaxValue')->willReturn(500);
        $product->method('getId')->willReturn('WIDGET-42');
        $product->method('getCategory')->willReturn('Widgets');
        $product->method('getEan')->willReturn('1234567890123');
        $product->method('getPhotoUrl')->willReturn('https://example.com/img.jpg');
        $product->method('getCategoryIds')->willReturn([7, 8]);

        $cart = new Cart(
            totalValue: 7500,
            totalNetValue: 6000,
            totalTaxValue: 1500,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: [$this->createMockCartItem($product, 3)]
        );

        $productDataArray = $cart->getAsArray()['products'][0];

        $this->assertSame('Widget', $productDataArray['name']);
        $this->assertSame(3, $productDataArray['quantity']);
        $this->assertSame(2500, $productDataArray['price']);
        $this->assertSame(2000, $productDataArray['netPrice']);
        $this->assertSame(25, $productDataArray['vatRate']);
        $this->assertSame(500, $productDataArray['vatAmount']);
        $this->assertSame('WIDGET-42', $productDataArray['externalId']);
        $this->assertSame('Widgets', $productDataArray['category']);
        $this->assertSame('1234567890123', $productDataArray['ean']);
        $this->assertSame('https://example.com/img.jpg', $productDataArray['photoUrl']);
        $this->assertSame([7, 8], $productDataArray['categoryIds']);
    }

    /** @param int[]|null $categoryIds */
    private function createMockProduct(?array $categoryIds = null): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCategoryIds')->willReturn($categoryIds);
        $product->method('getName')->willReturn('Test Product');
        $product->method('getPrice')->willReturn(1000);
        $product->method('getNetPrice')->willReturn(null);
        $product->method('getTaxRate')->willReturn(null);
        $product->method('getTaxValue')->willReturn(null);
        $product->method('getId')->willReturn('SKU-1');
        $product->method('getCategory')->willReturn('Electronics');
        $product->method('getEan')->willReturn(null);
        $product->method('getPhotoUrl')->willReturn(null);

        return $product;
    }

    private function createMockCartItem(ProductInterface $product, int $quantity = 1): CartItemInterface
    {
        $item = $this->createMock(CartItemInterface::class);
        $item->method('getProduct')->willReturn($product);
        $item->method('getQuantity')->willReturn($quantity);

        return $item;
    }
}
