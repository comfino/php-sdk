<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Product
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Product;

use Comfino\Shop\Cart;
use Comfino\Shop\Product\CategoryFilter;
use Comfino\Shop\Product\CategoryTree;
use Comfino\Shop\Product\CategoryTree\BuildStrategyInterface;
use Comfino\Shop\Product\CategoryTree\Descriptor;
use Comfino\Shop\Product\CategoryTree\Node;
use Comfino\Shop\Product\CategoryTree\NodeIterator;
use Comfino\Shop\Order\Cart\CartItemInterface;
use Comfino\Shop\Order\Cart\ProductInterface;
use PHPUnit\Framework\TestCase;

class CategoryFilterTest extends TestCase
{
    /** @param array<int, Node>|null $index */
    private function createMockBuildStrategy(NodeIterator $nodes, ?array $index = null): BuildStrategyInterface
    {
        $strategy = $this->createMock(BuildStrategyInterface::class);
        $strategy->method('build')->willReturn(new Descriptor($nodes, $index));

        return $strategy;
    }

    /**
     * @param Node[] $nodesArray
     * @param array<int, Node>|null $index
     */
    private function createCategoryTree(array $nodesArray, ?array $index = null): CategoryTree
    {
        return new CategoryTree($this->createMockBuildStrategy(new NodeIterator($nodesArray), $index));
    }

    /** @param int[] $categoryIds */
    private function createMockProduct(array $categoryIds): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCategoryIds')->willReturn($categoryIds);

        return $product;
    }

    /** @param int[] $categoryIds */
    private function createMockCartItem(array $categoryIds): CartItemInterface
    {
        $cartItem = $this->createMock(CartItemInterface::class);
        $cartItem->method('getProduct')->willReturn($this->createMockProduct($categoryIds));

        return $cartItem;
    }

    /** @param int[][] $cartItemsCategoryIds */
    private function createMockCart(array $cartItemsCategoryIds): Cart
    {
        $cartItems = [];

        foreach ($cartItemsCategoryIds as $categoryIds) {
            $cartItems[] = $this->createMockCartItem($categoryIds);
        }

        return new Cart(
            totalValue: 10000,
            totalNetValue: 8000,
            totalTaxValue: 2000,
            deliveryCost: 500,
            deliveryNetCost: 400,
            deliveryTaxRate: 25,
            deliveryTaxValue: 100,
            cartItems: $cartItems
        );
    }

    public function testIsCategoryAvailableWithEmptyExcludedList(): void
    {
        $node = new Node(1, 'Electronics');
        $tree = $this->createCategoryTree([$node], [1 => $node]);

        $filter = new CategoryFilter($tree);

        $this->assertTrue($filter->isCategoryAvailable(1, []));
    }

    public function testIsCategoryAvailableWithDirectExclusion(): void
    {
        $node = new Node(1, 'Electronics');
        $tree = $this->createCategoryTree([$node], [1 => $node]);

        $filter = new CategoryFilter($tree);

        $this->assertFalse($filter->isCategoryAvailable(1, [1]));
    }

    public function testIsCategoryAvailableWithNonExistentCategory(): void
    {
        $node = new Node(1, 'Electronics');
        $tree = $this->createCategoryTree([$node], [1 => $node]);

        $filter = new CategoryFilter($tree);

        $this->assertFalse($filter->isCategoryAvailable(999, []));
    }

    public function testIsCategoryAvailableWithExcludedParent(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $parent);

        $parent->setChildren(new NodeIterator([$child]));

        $tree = $this->createCategoryTree([$parent], [1 => $parent, 2 => $child]);

        $filter = new CategoryFilter($tree);

        $this->assertFalse($filter->isCategoryAvailable(2, [1]));
    }

    public function testIsCategoryAvailableWithExcludedGrandparent(): void
    {
        $grandparent = new Node(1, 'Electronics');
        $parent = new Node(2, 'Computers', $grandparent);
        $child = new Node(3, 'Laptops', $parent);

        $grandparent->setChildren(new NodeIterator([$parent]));

        $parent->setChildren(new NodeIterator([$child]));

        $tree = $this->createCategoryTree([$grandparent], [1 => $grandparent, 2 => $parent, 3 => $child]);

        $filter = new CategoryFilter($tree);

        $this->assertFalse($filter->isCategoryAvailable(3, [1]));
    }

    public function testIsCategoryAvailableWithExcludedSibling(): void
    {
        $parent = new Node(1, 'Electronics');
        $child1 = new Node(2, 'Laptops', $parent);
        $child2 = new Node(3, 'Phones', $parent);

        $parent->setChildren(new NodeIterator([$child1, $child2]));

        $tree = $this->createCategoryTree([$parent], [1 => $parent, 2 => $child1, 3 => $child2]);

        $filter = new CategoryFilter($tree);

        $this->assertTrue($filter->isCategoryAvailable(2, [3]));
    }

    public function testIsCategoryAvailableWithExcludedChild(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $parent);

        $parent->setChildren(new NodeIterator([$child]));

        $tree = $this->createCategoryTree([$parent], [1 => $parent, 2 => $child]);

        $filter = new CategoryFilter($tree);

        $this->assertTrue($filter->isCategoryAvailable(1, [2]));
    }

    public function testIsCategoryAvailableWithMultipleExclusions(): void
    {
        $root = new Node(1, 'Electronics');
        $child1 = new Node(2, 'Laptops', $root);
        $child2 = new Node(3, 'Phones', $root);

        $root->setChildren(new NodeIterator([$child1, $child2]));

        $tree = $this->createCategoryTree([$root], [1 => $root, 2 => $child1, 3 => $child2]);

        $filter = new CategoryFilter($tree);

        $this->assertFalse($filter->isCategoryAvailable(1, [1, 2, 3]));
        $this->assertFalse($filter->isCategoryAvailable(2, [1, 2, 3]));
        $this->assertFalse($filter->isCategoryAvailable(3, [1, 2, 3]));
    }

    public function testIsCategoryAvailableWithNonExistentExcludedCategory(): void
    {
        $node = new Node(1, 'Electronics');
        $tree = $this->createCategoryTree([$node], [1 => $node]);

        $filter = new CategoryFilter($tree);

        $this->assertTrue($filter->isCategoryAvailable(1, [999]));
    }

    public function testIsCartValidWithEmptyCart(): void
    {
        $tree = $this->createCategoryTree([]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([]);

        $this->assertTrue($filter->isCartValid($cart, [1, 2, 3]));
    }

    public function testIsCartValidWithEmptyExcludedList(): void
    {
        $node = new Node(1, 'Electronics');
        $tree = $this->createCategoryTree([$node], [1 => $node]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[1]]);

        $this->assertTrue($filter->isCartValid($cart, []));
    }

    public function testIsCartValidWithAllowedCategories(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');
        $tree = $this->createCategoryTree([$node1, $node2], [1 => $node1, 2 => $node2]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[2]]);

        $this->assertTrue($filter->isCartValid($cart, [1]));
    }

    public function testIsCartValidWithDirectExcludedCategory(): void
    {
        $node = new Node(1, 'Electronics');
        $tree = $this->createCategoryTree([$node], [1 => $node]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[1]]);

        $this->assertFalse($filter->isCartValid($cart, [1]));
    }

    public function testIsCartValidWithExcludedParentCategory(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $parent);
        $parent->setChildren(new NodeIterator([$child]));

        $tree = $this->createCategoryTree([$parent], [1 => $parent, 2 => $child]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[2]]);

        $this->assertFalse($filter->isCartValid($cart, [1]));
    }

    public function testIsCartValidWithMultipleCartItems(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');
        $node3 = new Node(3, 'Clothing');
        $tree = $this->createCategoryTree([$node1, $node2, $node3], [1 => $node1, 2 => $node2, 3 => $node3]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[2], [3]]);

        $this->assertTrue($filter->isCartValid($cart, [1]));
    }

    public function testIsCartValidWithMixedCategories(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');
        $tree = $this->createCategoryTree([$node1, $node2], [1 => $node1, 2 => $node2]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[1], [2]]);

        $this->assertFalse($filter->isCartValid($cart, [1]));
    }

    public function testIsCartValidWithProductInMultipleCategories(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Gaming');
        $tree = $this->createCategoryTree([$node1, $node2], [1 => $node1, 2 => $node2]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[1, 2]]);

        $this->assertFalse($filter->isCartValid($cart, [1]));
    }

    public function testIsCartValidWithComplexHierarchy(): void
    {
        $grandparent = new Node(1, 'Electronics');
        $parent = new Node(2, 'Computers', $grandparent);
        $child = new Node(3, 'Laptops', $parent);
        $sibling = new Node(4, 'Books');

        $grandparent->setChildren(new NodeIterator([$parent]));
        $parent->setChildren(new NodeIterator([$child]));

        $tree = $this->createCategoryTree(
            [$grandparent, $sibling],
            [1 => $grandparent, 2 => $parent, 3 => $child, 4 => $sibling]
        );

        $filter = new CategoryFilter($tree);

        $this->assertFalse($filter->isCartValid($this->createMockCart([[3], [4]]), [1]));
        $this->assertTrue($filter->isCartValid($this->createMockCart([[4]]), [1]));
        $this->assertFalse($filter->isCartValid($this->createMockCart([[3]]), [1]));
    }

    public function testIsCartValidWithNonExistentCategory(): void
    {
        $node = new Node(1, 'Electronics');
        $tree = $this->createCategoryTree([$node], [1 => $node]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[999]]);

        $this->assertFalse($filter->isCartValid($cart, [1]));
    }

    public function testIsCartValidWithEmptyProductCategories(): void
    {
        $node = new Node(1, 'Electronics');
        $tree = $this->createCategoryTree([$node], [1 => $node]);

        $filter = new CategoryFilter($tree);

        $cartItem = $this->createMock(CartItemInterface::class);
        $product = $this->createMock(ProductInterface::class);
        $product->method('getCategoryIds')->willReturn(null);
        $cartItem->method('getProduct')->willReturn($product);

        $cart = new Cart(
            totalValue: 10000,
            totalNetValue: 8000,
            totalTaxValue: 2000,
            deliveryCost: 500,
            deliveryNetCost: 400,
            deliveryTaxRate: 25,
            deliveryTaxValue: 100,
            cartItems: [$cartItem]
        );

        $this->assertTrue($filter->isCartValid($cart, [1]));
    }

    public function testIsCartValidWithMultipleExcludedCategories(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');
        $node3 = new Node(3, 'Clothing');
        $node4 = new Node(4, 'Toys');

        $tree = $this->createCategoryTree(
            [$node1, $node2, $node3, $node4],
            [1 => $node1, 2 => $node2, 3 => $node3, 4 => $node4]
        );

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[4]]);

        $this->assertTrue($filter->isCartValid($cart, [1, 2, 3]));
    }

    public function testIsCartValidWithDeepCategoryExclusion(): void
    {
        $level1 = new Node(1, 'Electronics');
        $level2 = new Node(2, 'Computers', $level1);
        $level3 = new Node(3, 'Laptops', $level2);
        $level4 = new Node(4, 'Gaming', $level3);

        $level1->setChildren(new NodeIterator([$level2]));
        $level2->setChildren(new NodeIterator([$level3]));
        $level3->setChildren(new NodeIterator([$level4]));

        $tree = $this->createCategoryTree([$level1], [1 => $level1, 2 => $level2, 3 => $level3, 4 => $level4]);

        $filter = new CategoryFilter($tree);

        $cart = $this->createMockCart([[4]]);

        $this->assertFalse($filter->isCartValid($cart, [1]));
    }
}
