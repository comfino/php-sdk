<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Support
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Support;

use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\Product;
use Comfino\Shop\Product\Category;
use Comfino\Shop\Product\CategoryManager;
use Comfino\Shop\Product\CategoryTree;
use Comfino\Shop\Product\CategoryTree\BuildStrategyInterface;
use Comfino\Shop\Product\CategoryTree\Descriptor;

/**
 * Builders of real cart, product and category tree objects for tag/category filtering tests.
 */
trait ProductFilteringFixtures
{
    /**
     * @param string[]|null $tags
     * @param int[]|null $categoryIds
     */
    private static function product(string $id, ?array $tags = null, ?array $categoryIds = null): Product
    {
        return new Product(name: "Product $id", price: 10000, id: $id, categoryIds: $categoryIds, tags: $tags);
    }

    private static function cart(Product ...$products): Cart
    {
        return new Cart(
            totalValue: 10000 * count($products),
            totalNetValue: null,
            totalTaxValue: null,
            deliveryCost: 0,
            deliveryNetCost: null,
            deliveryTaxRate: null,
            deliveryTaxValue: null,
            cartItems: array_map(static fn (Product $product): CartItem => new CartItem($product, 1), $products)
        );
    }

    /**
     * Electronics (1) > Laptops (10) > Gaming laptops (100); Electronics (1) > Phones (11); Clothing (2).
     */
    private static function categoryTree(): CategoryTree
    {
        $descriptor = CategoryManager::buildCategoryTree([
            new Category(1, 'Electronics', 0, [
                new Category(10, 'Laptops', 0, [new Category(100, 'Gaming laptops', 0, [])]),
                new Category(11, 'Phones', 1, []),
            ]),
            new Category(2, 'Clothing', 1, []),
        ]);

        return new CategoryTree(new class ($descriptor) implements BuildStrategyInterface {
            public function __construct(private readonly Descriptor $descriptor)
            {
            }

            public function build(): Descriptor
            {
                return $this->descriptor;
            }
        });
    }
}
