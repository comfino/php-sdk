<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Product\CategoryTree
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Product\CategoryTree;

use Comfino\Shop\Product\CategoryTree\Node;
use Comfino\Shop\Product\CategoryTree\NodeIterator;
use PHPUnit\Framework\TestCase;

class NodeTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $node = new Node(1, 'Electronics');

        $this->assertSame(1, $node->getId());
        $this->assertSame('Electronics', $node->getName());
        $this->assertNull($node->getParent());
        $this->assertNull($node->getChildren());
    }

    public function testConstructorWithParent(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $parent);

        $this->assertSame($parent, $child->getParent());
        $this->assertSame(2, $child->getId());
    }

    public function testConstructorWithChildren(): void
    {
        $child1 = new Node(2, 'Laptops');
        $child2 = new Node(3, 'Phones');
        $children = new NodeIterator([$child1, $child2]);
        $parent = new Node(1, 'Electronics', null, $children);

        $this->assertSame($children, $parent->getChildren());
        $this->assertCount(2, $parent->getChildren());
    }

    public function testSetParent(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops');

        $this->assertNull($child->getParent());

        $child->setParent($parent);

        $this->assertSame($parent, $child->getParent());
    }

    public function testSetChildren(): void
    {
        $parent = new Node(1, 'Electronics');
        $child1 = new Node(2, 'Laptops');
        $child2 = new Node(3, 'Phones');
        $children = new NodeIterator([$child1, $child2]);

        $this->assertNull($parent->getChildren());

        $parent->setChildren($children);

        $this->assertSame($children, $parent->getChildren());
        $this->assertCount(2, $parent->getChildren());
    }

    public function testIsRoot(): void
    {
        $root = new Node(1, 'Electronics');
        $parent = new Node(2, 'Computers');
        $child = new Node(3, 'Laptops', $parent);

        $this->assertTrue($root->isRoot());
        $this->assertTrue($parent->isRoot());
        $this->assertFalse($child->isRoot());
    }

    public function testIsLeaf(): void
    {
        $leafNode = new Node(1, 'Gaming Laptops');
        $parentNode = new Node(2, 'Laptops');
        $childNode = new Node(3, 'MacBook');

        $parentNode->setChildren(new NodeIterator([$childNode]));

        $this->assertTrue($leafNode->isLeaf());
        $this->assertFalse($parentNode->isLeaf());
        $this->assertTrue($childNode->isLeaf());
    }

    public function testHasChildren(): void
    {
        $nodeWithoutChildren = new Node(1, 'Category');
        $nodeWithEmptyChildren = new Node(2, 'Category');
        $nodeWithChildren = new Node(3, 'Category');

        $nodeWithEmptyChildren->setChildren(new NodeIterator([]));
        $nodeWithChildren->setChildren(new NodeIterator([new Node(4, 'Child')]));

        $this->assertFalse($nodeWithoutChildren->hasChildren());
        $this->assertFalse($nodeWithEmptyChildren->hasChildren());
        $this->assertTrue($nodeWithChildren->hasChildren());
    }

    public function testIsParentOf(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $parent);
        $unrelated = new Node(3, 'Books');

        $this->assertTrue($parent->isParentOf($child));
        $this->assertFalse($parent->isParentOf($unrelated));
        $this->assertFalse($child->isParentOf($parent));
    }

    public function testIsChildOf(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $parent);
        $unrelated = new Node(3, 'Books');

        $this->assertTrue($child->isChildOf($parent));
        $this->assertFalse($parent->isChildOf($child));
        $this->assertFalse($child->isChildOf($unrelated));
    }

    public function testIsAncestorOfDirectChild(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $parent);

        $this->assertTrue($parent->isAncestorOf($child));
        $this->assertFalse($child->isAncestorOf($parent));
    }

    public function testIsAncestorOfGrandchild(): void
    {
        $grandparent = new Node(1, 'Electronics');
        $parent = new Node(2, 'Computers', $grandparent);
        $child = new Node(3, 'Laptops', $parent);

        $grandparent->setChildren(new NodeIterator([$parent]));
        $parent->setChildren(new NodeIterator([$child]));

        $this->assertTrue($grandparent->isAncestorOf($child));
        $this->assertTrue($parent->isAncestorOf($child));
        $this->assertFalse($child->isAncestorOf($grandparent));
    }

    public function testIsAncestorOfUnrelatedNode(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');

        $this->assertFalse($node1->isAncestorOf($node2));
        $this->assertFalse($node2->isAncestorOf($node1));
    }

    public function testIsAncestorOfLeafNode(): void
    {
        $leafNode = new Node(1, 'Leaf');
        $otherNode = new Node(2, 'Other');

        $this->assertFalse($leafNode->isAncestorOf($otherNode));
    }

    public function testIsDescendantOfDirectParent(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $parent);

        $this->assertTrue($child->isDescendantOf($parent));
        $this->assertFalse($parent->isDescendantOf($child));
    }

    public function testIsDescendantOfGrandparent(): void
    {
        $grandparent = new Node(1, 'Electronics');
        $parent = new Node(2, 'Computers', $grandparent);
        $child = new Node(3, 'Laptops', $parent);

        $this->assertTrue($child->isDescendantOf($parent));
        $this->assertTrue($child->isDescendantOf($grandparent));
        $this->assertTrue($parent->isDescendantOf($grandparent));
        $this->assertFalse($grandparent->isDescendantOf($child));
    }

    public function testIsDescendantOfUnrelatedNode(): void
    {
        $parent = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $parent);
        $unrelated = new Node(3, 'Books');

        $this->assertFalse($child->isDescendantOf($unrelated));
        $this->assertFalse($unrelated->isDescendantOf($parent));
    }

    public function testIsDescendantOfRootNode(): void
    {
        $root = new Node(1, 'Root');
        $otherNode = new Node(2, 'Other');

        $this->assertFalse($root->isDescendantOf($otherNode));
    }

    public function testGetPathToRootForRootNode(): void
    {
        $root = new Node(1, 'Electronics');

        $path = $root->getPathToRoot();

        $this->assertCount(1, $path);
        $this->assertSame($root, $path->current());
    }

    public function testGetPathToRootForChildNode(): void
    {
        $root = new Node(1, 'Electronics');
        $child = new Node(2, 'Laptops', $root);

        $path = $child->getPathToRoot();

        $this->assertCount(2, $path);

        $pathArray = iterator_to_array($path);

        $this->assertSame($child, $pathArray[0]);
        $this->assertSame($root, $pathArray[1]);
    }

    public function testGetPathToRootForDeepHierarchy(): void
    {
        $root = new Node(1, 'Electronics');
        $level1 = new Node(2, 'Computers', $root);
        $level2 = new Node(3, 'Laptops', $level1);
        $level3 = new Node(4, 'Gaming', $level2);

        $path = $level3->getPathToRoot();

        $this->assertCount(4, $path);

        $pathArray = iterator_to_array($path);

        $this->assertSame($level3, $pathArray[0]);
        $this->assertSame($level2, $pathArray[1]);
        $this->assertSame($level1, $pathArray[2]);
        $this->assertSame($root, $pathArray[3]);
    }

    public function testComplexHierarchy(): void
    {
        // Build a complex tree: Electronics -> [Computers, Phones] -> [Laptops, Desktops] / [iPhone, Android]
        $electronics = new Node(1, 'Electronics');

        $computers = new Node(2, 'Computers', $electronics);
        $phones = new Node(3, 'Phones', $electronics);

        $laptops = new Node(4, 'Laptops', $computers);
        $desktops = new Node(5, 'Desktops', $computers);

        $iphone = new Node(6, 'iPhone', $phones);
        $android = new Node(7, 'Android', $phones);

        $computers->setChildren(new NodeIterator([$laptops, $desktops]));
        $phones->setChildren(new NodeIterator([$iphone, $android]));
        $electronics->setChildren(new NodeIterator([$computers, $phones]));

        // Test ancestor relationships.
        $this->assertTrue($electronics->isAncestorOf($laptops));
        $this->assertTrue($electronics->isAncestorOf($iphone));
        $this->assertTrue($computers->isAncestorOf($laptops));
        $this->assertFalse($computers->isAncestorOf($iphone));

        // Test descendant relationships.
        $this->assertTrue($laptops->isDescendantOf($electronics));
        $this->assertTrue($laptops->isDescendantOf($computers));
        $this->assertFalse($laptops->isDescendantOf($phones));

        // Test paths.
        $this->assertCount(3, $laptops->getPathToRoot());
    }
}
