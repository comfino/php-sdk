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
use PHPUnit\Framework\TestCase;

class CategoryTest extends TestCase
{
    public function testConstructorWithoutChildren(): void
    {
        $category = new Category(1, 'Electronics', 0, []);

        $this->assertSame(1, $category->id);
        $this->assertSame('Electronics', $category->name);
        $this->assertSame(0, $category->position);
        $this->assertSame([], $category->children);
    }

    public function testConstructorWithChildren(): void
    {
        $child1 = new Category(2, 'Laptops', 1, []);
        $child2 = new Category(3, 'Phones', 2, []);

        $parent = new Category(1, 'Electronics', 0, [$child1, $child2]);

        $this->assertSame(1, $parent->id);
        $this->assertSame('Electronics', $parent->name);
        $this->assertSame(0, $parent->position);
        $this->assertCount(2, $parent->children);
        $this->assertSame($child1, $parent->children[0]);
        $this->assertSame($child2, $parent->children[1]);
    }

    public function testReadonlyProperties(): void
    {
        $category = new Category(1, 'Electronics', 0, []);

        $this->assertSame(1, $category->id);
        $this->assertSame('Electronics', $category->name);
        $this->assertSame(0, $category->position);
        $this->assertSame([], $category->children);
    }

    public function testNestedCategoryStructure(): void
    {
        $grandchild = new Category(4, 'Gaming Laptops', 0, []);
        $child = new Category(2, 'Laptops', 0, [$grandchild]);
        $parent = new Category(1, 'Electronics', 0, [$child]);

        $this->assertSame(1, $parent->id);
        $this->assertSame('Electronics', $parent->name);
        $this->assertCount(1, $parent->children);

        $firstChild = $parent->children[0];

        $this->assertSame(2, $firstChild->id);
        $this->assertSame('Laptops', $firstChild->name);
        $this->assertCount(1, $firstChild->children);

        $firstGrandchild = $firstChild->children[0];

        $this->assertSame(4, $firstGrandchild->id);
        $this->assertSame('Gaming Laptops', $firstGrandchild->name);
        $this->assertCount(0, $firstGrandchild->children);
    }

    public function testMultipleSiblings(): void
    {
        $child1 = new Category(2, 'Laptops', 1, []);
        $child2 = new Category(3, 'Phones', 2, []);
        $child3 = new Category(4, 'Tablets', 3, []);

        $parent = new Category(1, 'Electronics', 0, [$child1, $child2, $child3]);

        $this->assertCount(3, $parent->children);
        $this->assertSame('Laptops', $parent->children[0]->name);
        $this->assertSame('Phones', $parent->children[1]->name);
        $this->assertSame('Tablets', $parent->children[2]->name);
    }

    public function testPositionOrdering(): void
    {
        $category1 = new Category(1, 'First', 1, []);
        $category2 = new Category(2, 'Second', 2, []);
        $category3 = new Category(3, 'Third', 3, []);

        $this->assertSame(1, $category1->position);
        $this->assertSame(2, $category2->position);
        $this->assertSame(3, $category3->position);
    }

    public function testComplexHierarchy(): void
    {
        // Build: Electronics -> [Computers -> [Laptops, Desktops], Phones -> [iPhone, Android]]
        $laptops = new Category(4, 'Laptops', 1, []);
        $desktops = new Category(5, 'Desktops', 2, []);
        $computers = new Category(2, 'Computers', 1, [$laptops, $desktops]);

        $iphone = new Category(6, 'iPhone', 1, []);
        $android = new Category(7, 'Android', 2, []);
        $phones = new Category(3, 'Phones', 2, [$iphone, $android]);

        $electronics = new Category(1, 'Electronics', 0, [$computers, $phones]);

        $this->assertSame(1, $electronics->id);
        $this->assertCount(2, $electronics->children);

        $computersChild = $electronics->children[0];

        $this->assertSame(2, $computersChild->id);
        $this->assertCount(2, $computersChild->children);
        $this->assertSame('Laptops', $computersChild->children[0]->name);
        $this->assertSame('Desktops', $computersChild->children[1]->name);

        $phonesChild = $electronics->children[1];

        $this->assertSame(3, $phonesChild->id);
        $this->assertCount(2, $phonesChild->children);
        $this->assertSame('iPhone', $phonesChild->children[0]->name);
        $this->assertSame('Android', $phonesChild->children[1]->name);
    }

    public function testEmptyStringName(): void
    {
        $this->assertSame('', (new Category(1, '', 0, []))->name);
    }

    public function testNegativePosition(): void
    {
        $this->assertSame(-1, (new Category(1, 'Category', -1, []))->position);
    }

    public function testLargeIds(): void
    {
        $this->assertSame(999999, (new Category(999999, 'Category', 0, []))->id);
    }
}
