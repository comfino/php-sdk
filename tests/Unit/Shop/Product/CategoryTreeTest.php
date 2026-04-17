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

use Comfino\Shop\Product\CategoryTree;
use Comfino\Shop\Product\CategoryTree\BuildStrategyInterface;
use Comfino\Shop\Product\CategoryTree\Descriptor;
use Comfino\Shop\Product\CategoryTree\Node;
use Comfino\Shop\Product\CategoryTree\NodeIterator;
use PHPUnit\Framework\TestCase;

class CategoryTreeTest extends TestCase
{
    /** @param array<int, Node>|null $index */
    private function createMockBuildStrategy(NodeIterator $nodes, ?array $index = null): BuildStrategyInterface
    {
        $strategy = $this->createMock(BuildStrategyInterface::class);
        $strategy->method('build')->willReturn(new Descriptor($nodes, $index));

        return $strategy;
    }

    public function testGetNodesCallsBuildStrategy(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');
        $nodes = new NodeIterator([$node1, $node2]);

        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $result = $tree->getNodes();

        $this->assertSame($nodes, $result);
        $this->assertCount(2, $result);
    }

    public function testGetNodesCallsBuildStrategyOnlyOnce(): void
    {
        $nodes = new NodeIterator([new Node(1, 'Category')]);

        $strategy = $this->createMock(BuildStrategyInterface::class);
        $strategy->expects($this->once())
            ->method('build')
            ->willReturn(new Descriptor($nodes, null));

        $tree = new CategoryTree($strategy);

        $tree->getNodes();
        $tree->getNodes();
        $tree->getNodes();
    }

    public function testGetNodesWithEmptyTree(): void
    {
        $nodes = new NodeIterator([]);
        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $this->assertCount(0, $tree->getNodes());
    }

    public function testGetNodeIdsWithEmptyTree(): void
    {
        $nodes = new NodeIterator([]);
        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $this->assertSame([], $tree->getNodeIds());
    }

    public function testGetNodeIdsWithFlatTree(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');
        $node3 = new Node(3, 'Clothing');
        $nodes = new NodeIterator([$node1, $node2, $node3]);

        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $this->assertSame([1, 2, 3], $tree->getNodeIds());
    }

    public function testGetNodeIdsWithNestedTree(): void
    {
        $child1 = new Node(2, 'Laptops');
        $child2 = new Node(3, 'Phones');
        $parent = new Node(1, 'Electronics');
        $parent->setChildren(new NodeIterator([$child1, $child2]));
        $child1->setParent($parent);
        $child2->setParent($parent);

        $nodes = new NodeIterator([$parent]);
        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $this->assertSame([1, 2, 3], $tree->getNodeIds());
    }

    public function testGetNodeIdsWithDeepHierarchy(): void
    {
        $level3 = new Node(4, 'Gaming Laptops');
        $level2 = new Node(3, 'Laptops');
        $level2->setChildren(new NodeIterator([$level3]));
        $level3->setParent($level2);

        $level1 = new Node(2, 'Computers');
        $level1->setChildren(new NodeIterator([$level2]));
        $level2->setParent($level1);

        $root = new Node(1, 'Electronics');
        $root->setChildren(new NodeIterator([$level1]));
        $level1->setParent($root);

        $nodes = new NodeIterator([$root]);
        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $this->assertSame([1, 2, 3, 4], $tree->getNodeIds());
    }

    public function testGetNodeIdsWithIndexFlatTree(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');
        $node3 = new Node(3, 'Clothing');

        $nodes = new NodeIterator([$node1, $node2, $node3]);
        $index = [1 => $node1, 2 => $node2, 3 => $node3];

        $strategy = $this->createMockBuildStrategy($nodes, $index);
        $tree = new CategoryTree($strategy);

        $this->assertSame([1, 2, 3], $tree->getNodeIds());
    }

    public function testGetNodeIdsWithIndexNestedTree(): void
    {
        $child1 = new Node(2, 'Laptops');
        $child2 = new Node(3, 'Phones');
        $parent = new Node(1, 'Electronics');
        $parent->setChildren(new NodeIterator([$child1, $child2]));
        $child1->setParent($parent);
        $child2->setParent($parent);

        $nodes = new NodeIterator([$parent]);
        $index = [1 => $parent, 2 => $child1, 3 => $child2];

        $strategy = $this->createMockBuildStrategy($nodes, $index);
        $tree = new CategoryTree($strategy);

        $this->assertSame([1, 2, 3], $tree->getNodeIds());
    }

    public function testGetNodeIdsWithIndexDeepHierarchy(): void
    {
        $level3 = new Node(4, 'Gaming Laptops');
        $level2 = new Node(3, 'Laptops');
        $level2->setChildren(new NodeIterator([$level3]));
        $level3->setParent($level2);

        $level1 = new Node(2, 'Computers');
        $level1->setChildren(new NodeIterator([$level2]));
        $level2->setParent($level1);

        $root = new Node(1, 'Electronics');
        $root->setChildren(new NodeIterator([$level1]));
        $level1->setParent($root);

        $nodes = new NodeIterator([$root]);
        $index = [1 => $root, 2 => $level1, 3 => $level2, 4 => $level3];

        $strategy = $this->createMockBuildStrategy($nodes, $index);
        $tree = new CategoryTree($strategy);

        $this->assertSame([1, 2, 3, 4], $tree->getNodeIds());
    }

    public function testGetNodeIdsFromRootNode(): void
    {
        $child1 = new Node(3, 'Gaming');
        $child2 = new Node(4, 'Business');

        $laptops = new Node(2, 'Laptops');
        $laptops->setChildren(new NodeIterator([$child1, $child2]));
        $child1->setParent($laptops);
        $child2->setParent($laptops);

        $electronics = new Node(1, 'Electronics');
        $books = new Node(5, 'Books');

        $nodes = new NodeIterator([$electronics, $laptops, $books]);

        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $this->assertSame([2, 3, 4], $tree->getNodeIds($laptops));
    }

    public function testGetPathNodeIds(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Computers');
        $node3 = new Node(3, 'Laptops');

        $pathNodes = new NodeIterator([$node3, $node2, $node1]);

        $strategy = $this->createMockBuildStrategy(new NodeIterator([]));
        $tree = new CategoryTree($strategy);

        $this->assertSame([3, 2, 1], $tree->getPathNodeIds($pathNodes));
    }

    public function testGetPathNodeIdsWithEmptyPath(): void
    {
        $pathNodes = new NodeIterator([]);

        $strategy = $this->createMockBuildStrategy(new NodeIterator([]));
        $tree = new CategoryTree($strategy);

        $this->assertSame([], $tree->getPathNodeIds($pathNodes));
    }

    public function testGetNodeByIdWithFlatTree(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');
        $node3 = new Node(3, 'Clothing');

        $nodes = new NodeIterator([$node1, $node2, $node3]);
        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $result = $tree->getNodeById(2);

        $this->assertSame($node2, $result);
        $this->assertSame('Books', $result->getName());
    }

    public function testGetNodeByIdWithNestedTree(): void
    {
        $child1 = new Node(2, 'Laptops');
        $child2 = new Node(3, 'Phones');
        $parent = new Node(1, 'Electronics');
        $parent->setChildren(new NodeIterator([$child1, $child2]));
        $child1->setParent($parent);
        $child2->setParent($parent);

        $nodes = new NodeIterator([$parent]);
        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $result = $tree->getNodeById(3);

        $this->assertSame($child2, $result);
        $this->assertSame('Phones', $result->getName());
    }

    public function testGetNodeByIdWithIndex(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');

        $nodes = new NodeIterator([$node1, $node2]);
        $index = [1 => $node1, 2 => $node2];

        $strategy = $this->createMockBuildStrategy($nodes, $index);
        $tree = new CategoryTree($strategy);

        $this->assertSame($node2, $tree->getNodeById(2));
    }

    public function testGetNodeByIdNotFound(): void
    {
        $node1 = new Node(1, 'Electronics');
        $nodes = new NodeIterator([$node1]);

        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $this->assertNull($tree->getNodeById(999));
    }

    public function testGetNodeByIdBuildsIndex(): void
    {
        $node1 = new Node(1, 'Electronics');
        $node2 = new Node(2, 'Books');

        $nodes = new NodeIterator([$node1, $node2]);
        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $result1 = $tree->getNodeById(1);
        $result2 = $tree->getNodeById(2);

        $this->assertSame($node1, $result1);
        $this->assertSame($node2, $result2);
    }

    public function testGetNodeByIdFromRootNode(): void
    {
        $grandchild = new Node(4, 'Gaming Laptops');
        $child = new Node(3, 'Laptops');
        $child->setChildren(new NodeIterator([$grandchild]));
        $grandchild->setParent($child);

        $parent = new Node(2, 'Computers');
        $parent->setChildren(new NodeIterator([$child]));
        $child->setParent($parent);

        $root = new Node(1, 'Electronics');

        $nodes = new NodeIterator([$root, $parent]);
        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $this->assertSame($grandchild, $tree->getNodeById(4, $parent));
    }

    public function testGetNodeByIdFromRootNodeNotFound(): void
    {
        $child = new Node(2, 'Laptops');
        $parent = new Node(1, 'Electronics');
        $parent->setChildren(new NodeIterator([$child]));
        $child->setParent($parent);

        $otherRoot = new Node(3, 'Books');

        $nodes = new NodeIterator([$parent, $otherRoot]);
        $strategy = $this->createMockBuildStrategy($nodes);
        $tree = new CategoryTree($strategy);

        $this->assertNull($tree->getNodeById(2, $otherRoot));
    }

    public function testComplexTreeStructure(): void
    {
        // Build complex tree: Electronics -> [Computers -> [Laptops, Desktops], Phones -> [iPhone, Android]]
        $laptops = new Node(4, 'Laptops');
        $desktops = new Node(5, 'Desktops');
        $computers = new Node(2, 'Computers');
        $computers->setChildren(new NodeIterator([$laptops, $desktops]));
        $laptops->setParent($computers);
        $desktops->setParent($computers);

        $iphone = new Node(6, 'iPhone');
        $android = new Node(7, 'Android');
        $phones = new Node(3, 'Phones');
        $phones->setChildren(new NodeIterator([$iphone, $android]));
        $iphone->setParent($phones);
        $android->setParent($phones);

        $electronics = new Node(1, 'Electronics');
        $electronics->setChildren(new NodeIterator([$computers, $phones]));
        $computers->setParent($electronics);
        $phones->setParent($electronics);

        $books = new Node(8, 'Books');

        $nodes = new NodeIterator([$electronics, $books]);
        $index = [
            1 => $electronics, 2 => $computers, 3 => $phones,
            4 => $laptops, 5 => $desktops, 6 => $iphone, 7 => $android, 8 => $books,
        ];

        $strategy = $this->createMockBuildStrategy($nodes, $index);
        $tree = new CategoryTree($strategy);

        // Test getNodeIds (with index, returns array_keys directly).
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $tree->getNodeIds());

        // Test getNodeById (note: calling getNodeIds first builds the internal index).
        $this->assertSame($laptops, $tree->getNodeById(4));
        $this->assertSame($iphone, $tree->getNodeById(6));
        $this->assertSame($books, $tree->getNodeById(8));

        // Test getNodeIds from specific root.
        $this->assertSame([2, 4, 5], $tree->getNodeIds($computers));
        $this->assertSame([3, 6, 7], $tree->getNodeIds($phones));
    }
}
