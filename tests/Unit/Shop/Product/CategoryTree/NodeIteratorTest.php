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

class NodeIteratorTest extends TestCase
{
    public function testConstructorWithEmptyArray(): void
    {
        $this->assertCount(0, new NodeIterator([]));
    }

    public function testConstructorWithNodes(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');
        $node3 = new Node(3, 'Category 3');

        $this->assertCount(3, new NodeIterator([$node1, $node2, $node3]));
    }

    public function testCount(): void
    {
        $nodes = [new Node(1, 'Category 1'), new Node(2, 'Category 2')];

        $this->assertSame(2, (new NodeIterator($nodes))->count());
    }

    public function testCountEmpty(): void
    {
        $this->assertSame(0, (new NodeIterator([]))->count());
    }

    public function testIterationWithForeach(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');
        $node3 = new Node(3, 'Category 3');

        $iteratedNodes = [];

        foreach (new NodeIterator([$node1, $node2, $node3]) as $node) {
            $iteratedNodes[] = $node;
        }

        $this->assertCount(3, $iteratedNodes);
        $this->assertSame($node1, $iteratedNodes[0]);
        $this->assertSame($node2, $iteratedNodes[1]);
        $this->assertSame($node3, $iteratedNodes[2]);
    }

    public function testRewind(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');

        $iterator = new NodeIterator([$node1, $node2]);

        $iterator->next();

        $this->assertSame($node2, $iterator->current());

        $iterator->rewind();

        $this->assertSame($node1, $iterator->current());
    }

    public function testCurrent(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');

        $this->assertSame($node1, (new NodeIterator([$node1, $node2]))->current());
    }

    public function testKey(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');

        $iterator = new NodeIterator([$node1, $node2]);

        $this->assertSame(0, $iterator->key());

        $iterator->next();

        $this->assertSame(1, $iterator->key());
    }

    public function testNext(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');
        $node3 = new Node(3, 'Category 3');

        $iterator = new NodeIterator([$node1, $node2, $node3]);

        $this->assertSame($node1, $iterator->current());

        $iterator->next();

        $this->assertSame($node2, $iterator->current());

        $iterator->next();

        $this->assertSame($node3, $iterator->current());
    }

    public function testValid(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');

        $iterator = new NodeIterator([$node1, $node2]);

        $this->assertTrue($iterator->valid());

        $iterator->next();

        $this->assertTrue($iterator->valid());

        $iterator->next();

        $this->assertFalse($iterator->valid());
    }

    public function testValidOnEmptyIterator(): void
    {
        $this->assertFalse((new NodeIterator([]))->valid());
    }

    public function testFullIterationCycle(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');
        $node3 = new Node(3, 'Category 3');

        $iterator = new NodeIterator([$node1, $node2, $node3]);

        $collectedIds = [];

        $iterator->rewind();

        while ($iterator->valid()) {
            $collectedIds[] = $iterator->current()->getId();

            $iterator->next();
        }

        $this->assertSame([1, 2, 3], $collectedIds);
    }

    public function testMultipleIterations(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');

        $iterator = new NodeIterator([$node1, $node2]);

        // First iteration
        $firstIteration = [];

        foreach ($iterator as $node) {
            $firstIteration[] = $node->getId();
        }

        // Second iteration
        $secondIteration = [];

        foreach ($iterator as $node) {
            $secondIteration[] = $node->getId();
        }

        $this->assertSame([1, 2], $firstIteration);
        $this->assertSame([1, 2], $secondIteration);
    }

    public function testIteratorToArray(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');
        $node3 = new Node(3, 'Category 3');

        $array = iterator_to_array(new NodeIterator([$node1, $node2, $node3]));

        $this->assertCount(3, $array);
        $this->assertSame($node1, $array[0]);
        $this->assertSame($node2, $array[1]);
        $this->assertSame($node3, $array[2]);
    }

    public function testNonSequentialKeys(): void
    {
        $node1 = new Node(1, 'Category 1');
        $node2 = new Node(2, 'Category 2');
        $node3 = new Node(3, 'Category 3');

        // Create array with non-sequential keys.
        $iterator = new NodeIterator([5 => $node1, 10 => $node2, 15 => $node3]);

        $this->assertCount(3, $iterator);

        $iterator->rewind();

        $this->assertSame(5, $iterator->key());
        $this->assertSame($node1, $iterator->current());

        $iterator->next();

        $this->assertSame(10, $iterator->key());
        $this->assertSame($node2, $iterator->current());

        $iterator->next();

        $this->assertSame(15, $iterator->key());
        $this->assertSame($node3, $iterator->current());
    }
}
