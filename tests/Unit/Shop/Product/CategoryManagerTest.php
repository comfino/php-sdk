<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Product
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Product;

use Comfino\Shop\Product\Category;
use Comfino\Shop\Product\CategoryManager;
use Comfino\Shop\Product\CategoryTree\Descriptor;
use Comfino\Shop\Product\CategoryTree\Node;
use Comfino\Shop\Product\CategoryTree\NodeIterator;
use PHPUnit\Framework\TestCase;

class CategoryManagerTest extends TestCase
{
    public function testBuildCategoryTreeWithEmptyArray(): void
    {
        $descriptor = CategoryManager::buildCategoryTree([]);

        $this->assertInstanceOf(NodeIterator::class, $descriptor->nodes);
        $this->assertCount(0, $descriptor->nodes);
        $this->assertIsArray($descriptor->index);
        $this->assertEmpty($descriptor->index);
    }

    public function testBuildCategoryTreeWithSingleCategory(): void
    {
        $category = new Category(1, 'Electronics', 0, []);

        $descriptor = CategoryManager::buildCategoryTree([$category]);

        $this->assertCount(1, $descriptor->nodes);
        $this->assertIsArray($descriptor->index);
        $this->assertCount(1, $descriptor->index);

        $node = $descriptor->nodes->current();

        $this->assertSame(1, $node->getId());
        $this->assertSame('Electronics', $node->getName());
        $this->assertNull($node->getParent());
        $this->assertFalse($node->hasChildren());
    }

    public function testBuildCategoryTreeWithMultipleFlatCategories(): void
    {
        $category1 = new Category(1, 'Electronics', 0, []);
        $category2 = new Category(2, 'Books', 1, []);
        $category3 = new Category(3, 'Clothing', 2, []);

        $descriptor = CategoryManager::buildCategoryTree([$category1, $category2, $category3]);

        $this->assertCount(3, $descriptor->nodes);
        $this->assertCount(3, $descriptor->index);

        $nodes = iterator_to_array($descriptor->nodes);

        $this->assertSame('Electronics', $nodes[0]->getName());
        $this->assertSame('Books', $nodes[1]->getName());
        $this->assertSame('Clothing', $nodes[2]->getName());

        foreach ($nodes as $node) {
            $this->assertNull($node->getParent());
            $this->assertFalse($node->hasChildren());
        }
    }

    public function testBuildCategoryTreeWithOneChildLevel(): void
    {
        $child1 = new Category(2, 'Laptops', 0, []);
        $child2 = new Category(3, 'Phones', 1, []);
        $parent = new Category(1, 'Electronics', 0, [$child1, $child2]);

        $descriptor = CategoryManager::buildCategoryTree([$parent]);

        $this->assertCount(1, $descriptor->nodes);
        $this->assertCount(3, $descriptor->index);

        $parentNode = $descriptor->nodes->current();

        $this->assertSame(1, $parentNode->getId());
        $this->assertSame('Electronics', $parentNode->getName());
        $this->assertTrue($parentNode->hasChildren());
        $this->assertCount(2, $parentNode->getChildren());

        $children = iterator_to_array($parentNode->getChildren());

        $this->assertSame(2, $children[0]->getId());
        $this->assertSame('Laptops', $children[0]->getName());
        $this->assertSame($parentNode, $children[0]->getParent());

        $this->assertSame(3, $children[1]->getId());
        $this->assertSame('Phones', $children[1]->getName());
        $this->assertSame($parentNode, $children[1]->getParent());
    }

    public function testBuildCategoryTreeWithDeepHierarchy(): void
    {
        $grandchild = new Category(4, 'Gaming Laptops', 0, []);
        $child = new Category(3, 'Laptops', 0, [$grandchild]);
        $parent = new Category(2, 'Computers', 0, [$child]);
        $root = new Category(1, 'Electronics', 0, [$parent]);

        $descriptor = CategoryManager::buildCategoryTree([$root]);

        $this->assertCount(1, $descriptor->nodes);
        $this->assertCount(4, $descriptor->index);

        $rootNode = $descriptor->nodes->current();

        $this->assertSame(1, $rootNode->getId());
        $this->assertTrue($rootNode->hasChildren());

        $parentNode = $rootNode->getChildren()->current();

        $this->assertSame(2, $parentNode->getId());
        $this->assertSame($rootNode, $parentNode->getParent());
        $this->assertTrue($parentNode->hasChildren());

        $childNode = $parentNode->getChildren()->current();

        $this->assertSame(3, $childNode->getId());
        $this->assertSame($parentNode, $childNode->getParent());
        $this->assertTrue($childNode->hasChildren());

        $grandchildNode = $childNode->getChildren()->current();

        $this->assertSame(4, $grandchildNode->getId());
        $this->assertSame('Gaming Laptops', $grandchildNode->getName());
        $this->assertSame($childNode, $grandchildNode->getParent());
        $this->assertFalse($grandchildNode->hasChildren());
    }

    public function testBuildCategoryTreeIndexContainsAllNodes(): void
    {
        $child1 = new Category(2, 'Laptops', 0, []);
        $child2 = new Category(3, 'Phones', 1, []);
        $parent = new Category(1, 'Electronics', 0, [$child1, $child2]);

        $descriptor = CategoryManager::buildCategoryTree([$parent]);

        $this->assertArrayHasKey(1, $descriptor->index);
        $this->assertArrayHasKey(2, $descriptor->index);
        $this->assertArrayHasKey(3, $descriptor->index);

        $this->assertSame('Electronics', $descriptor->index[1]->getName());
        $this->assertSame('Laptops', $descriptor->index[2]->getName());
        $this->assertSame('Phones', $descriptor->index[3]->getName());
    }

    public function testBuildCategoryTreeComplexStructure(): void
    {
        // Build: Electronics -> [Computers -> [Laptops, Desktops], Phones -> [iPhone, Android]]
        $laptops = new Category(4, 'Laptops', 0, []);
        $desktops = new Category(5, 'Desktops', 1, []);
        $computers = new Category(2, 'Computers', 0, [$laptops, $desktops]);

        $iphone = new Category(6, 'iPhone', 0, []);
        $android = new Category(7, 'Android', 1, []);
        $phones = new Category(3, 'Phones', 1, [$iphone, $android]);

        $electronics = new Category(1, 'Electronics', 0, [$computers, $phones]);

        $descriptor = CategoryManager::buildCategoryTree([$electronics]);

        $this->assertCount(1, $descriptor->nodes);
        $this->assertCount(7, $descriptor->index);

        $rootNode = $descriptor->nodes->current();

        $this->assertSame(1, $rootNode->getId());
        $this->assertCount(2, $rootNode->getChildren());

        $rootChildren = iterator_to_array($rootNode->getChildren());

        $computersNode = $rootChildren[0];

        $this->assertSame(2, $computersNode->getId());
        $this->assertCount(2, $computersNode->getChildren());

        $phonesNode = $rootChildren[1];

        $this->assertSame(3, $phonesNode->getId());
        $this->assertCount(2, $phonesNode->getChildren());

        // Verify all nodes are in index.
        for ($i = 1; $i <= 7; $i++) {
            $this->assertArrayHasKey($i, $descriptor->index);
            $this->assertInstanceOf(Node::class, $descriptor->index[$i]);
        }
    }

    public function testBuildCategoryTreeMultipleRootCategories(): void
    {
        $child1 = new Category(2, 'Laptops', 0, []);
        $electronics = new Category(1, 'Electronics', 0, [$child1]);

        $child2 = new Category(4, 'Fiction', 0, []);
        $books = new Category(3, 'Books', 1, [$child2]);

        $descriptor = CategoryManager::buildCategoryTree([$electronics, $books]);

        $this->assertCount(2, $descriptor->nodes);
        $this->assertCount(4, $descriptor->index);

        $nodes = iterator_to_array($descriptor->nodes);

        $this->assertSame(1, $nodes[0]->getId());
        $this->assertSame(3, $nodes[1]->getId());

        $this->assertTrue($nodes[0]->hasChildren());
        $this->assertTrue($nodes[1]->hasChildren());

        $this->assertCount(1, $nodes[0]->getChildren());
        $this->assertCount(1, $nodes[1]->getChildren());
    }

    public function testBuildCategoryTreeParentChildRelationships(): void
    {
        $child = new Category(2, 'Laptops', 0, []);
        $parent = new Category(1, 'Electronics', 0, [$child]);

        $descriptor = CategoryManager::buildCategoryTree([$parent]);

        $parentNode = $descriptor->nodes->current();
        $childNode = $parentNode->getChildren()->current();

        $this->assertTrue($parentNode->isParentOf($childNode));
        $this->assertTrue($childNode->isChildOf($parentNode));
        $this->assertTrue($parentNode->isAncestorOf($childNode));
        $this->assertTrue($childNode->isDescendantOf($parentNode));
    }

    public function testBuildCategoryTreeNodeIteratorFunctionality(): void
    {
        $category1 = new Category(1, 'Electronics', 0, []);
        $category2 = new Category(2, 'Books', 1, []);

        $descriptor = CategoryManager::buildCategoryTree([$category1, $category2]);

        $collectedIds = [];

        foreach ($descriptor->nodes as $node) {
            $collectedIds[] = $node->getId();
        }

        $this->assertSame([1, 2], $collectedIds);
    }

    public function testBuildCategoryTreeWithMultipleLevelsAndSiblings(): void
    {
        // Level 3
        $gaming = new Category(5, 'Gaming', 0, []);
        $business = new Category(6, 'Business', 1, []);

        // Level 2
        $laptops = new Category(3, 'Laptops', 0, [$gaming, $business]);
        $desktops = new Category(4, 'Desktops', 1, []);

        // Level 1
        $computers = new Category(2, 'Computers', 0, [$laptops, $desktops]);

        // Root
        $electronics = new Category(1, 'Electronics', 0, [$computers]);

        $descriptor = CategoryManager::buildCategoryTree([$electronics]);

        $this->assertCount(1, $descriptor->nodes);
        $this->assertCount(6, $descriptor->index);

        $rootNode = $descriptor->nodes->current();
        $computersNode = $rootNode->getChildren()->current();
        $computersChildren = iterator_to_array($computersNode->getChildren());

        $this->assertCount(2, $computersChildren);
        $this->assertSame('Laptops', $computersChildren[0]->getName());
        $this->assertSame('Desktops', $computersChildren[1]->getName());

        $laptopsNode = $computersChildren[0];

        $this->assertCount(2, $laptopsNode->getChildren());

        $laptopChildren = iterator_to_array($laptopsNode->getChildren());

        $this->assertSame('Gaming', $laptopChildren[0]->getName());
        $this->assertSame('Business', $laptopChildren[1]->getName());
    }

    public function testBuildCategoryTreePreservesNodeReferences(): void
    {
        $child = new Category(2, 'Laptops', 0, []);
        $parent = new Category(1, 'Electronics', 0, [$child]);

        $descriptor = CategoryManager::buildCategoryTree([$parent]);

        $parentNode = $descriptor->nodes->current();
        $childNodeFromParent = $parentNode->getChildren()->current();
        $childNodeFromIndex = $descriptor->index[2];

        $this->assertSame($childNodeFromParent, $childNodeFromIndex);
    }
}
