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

use Comfino\Shop\Order\Cart\Product;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testGetNameStripsTagsAndDecodesEntities(): void
    {
        $this->assertSame('Widget & Gadget', (new Product(name: '<b>Widget &amp; Gadget</b>', price: 1000))->getName());
    }

    public function testGetNameTrimsWhitespace(): void
    {
        $this->assertSame('Trimmed', (new Product(name: '  Trimmed  ', price: 500))->getName());
    }

    public function testGetPriceReturnsConstructedValue(): void
    {
        $this->assertSame(4999, (new Product(name: 'Item', price: 4999))->getPrice());
    }

    public function testNullableGettersReturnNullByDefault(): void
    {
        $product = new Product(name: 'Basic', price: 100);

        $this->assertNull($product->getNetPrice());
        $this->assertNull($product->getTaxRate());
        $this->assertNull($product->getTaxValue());
        $this->assertNull($product->getId());
        $this->assertNull($product->getCategory());
        $this->assertNull($product->getEan());
        $this->assertNull($product->getPhotoUrl());
        $this->assertNull($product->getCategoryIds());
    }

    public function testGetIdStripsTagsAndTrims(): void
    {
        $this->assertSame('SKU-99', (new Product(name: 'X', price: 1, id: ' <b>SKU-99</b> '))->getId());
    }

    public function testGetIdReturnsNullWhenNull(): void
    {
        $this->assertNull((new Product(name: 'X', price: 1, id: null))->getId());
    }

    public function testGetCategoryStripsTagsAndTrims(): void
    {
        $this->assertSame(
            'Electronics',
            (new Product(name: 'X', price: 1, category: ' <em>Electronics</em> '))->getCategory()
        );
    }

    public function testGetCategoryReturnsNullWhenNull(): void
    {
        $this->assertNull((new Product(name: 'X', price: 1, category: null))->getCategory());
    }

    public function testGetEanStripsTagsAndTrims(): void
    {
        $this->assertSame(
            '1234567890',
            (new Product(name: 'X', price: 1, ean: ' <span>1234567890</span> '))->getEan()
        );
    }

    public function testGetEanReturnsNullWhenNull(): void
    {
        $this->assertNull((new Product(name: 'X', price: 1, ean: null))->getEan());
    }

    public function testGetPhotoUrlStripsTagsAndTrims(): void
    {
        $this->assertSame(
            'https://example.com/img.jpg',
            (new Product(name: 'X', price: 1, photoUrl: ' https://example.com/img.jpg '))->getPhotoUrl()
        );
    }

    public function testGetPhotoUrlReturnsNullWhenNull(): void
    {
        $this->assertNull((new Product(name: 'X', price: 1, photoUrl: null))->getPhotoUrl());
    }

    public function testGetCategoryIdsReturnsArray(): void
    {
        $this->assertSame([1, 2, 3], (new Product(name: 'X', price: 1, categoryIds: [1, 2, 3]))->getCategoryIds());
    }

    public function testAllFieldsPopulated(): void
    {
        $product = new Product(
            name: 'Full Product',
            price: 5000,
            id: 'FULL-001',
            category: 'Computers',
            ean: '9876543210987',
            photoUrl: 'https://example.com/photo.jpg',
            categoryIds: [10, 20],
            netPrice: 4000,
            taxRate: 25,
            taxValue: 1000
        );

        $this->assertSame('Full Product', $product->getName());
        $this->assertSame(5000, $product->getPrice());
        $this->assertSame('FULL-001', $product->getId());
        $this->assertSame('Computers', $product->getCategory());
        $this->assertSame('9876543210987', $product->getEan());
        $this->assertSame('https://example.com/photo.jpg', $product->getPhotoUrl());
        $this->assertSame([10, 20], $product->getCategoryIds());
        $this->assertSame(4000, $product->getNetPrice());
        $this->assertSame(25, $product->getTaxRate());
        $this->assertSame(1000, $product->getTaxValue());
    }
}
