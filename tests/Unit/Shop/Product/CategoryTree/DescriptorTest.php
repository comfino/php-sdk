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

use Comfino\Shop\Product\CategoryTree\Descriptor;
use Comfino\Shop\Product\CategoryTree\Node;
use Comfino\Shop\Product\CategoryTree\NodeIterator;
use PHPUnit\Framework\TestCase;

final class DescriptorTest extends TestCase
{
    public function testConstructorAssignsNodes(): void
    {
        $iterator = new NodeIterator([]);
        $descriptor = new Descriptor($iterator, null);

        $this->assertSame($iterator, $descriptor->nodes);
    }

    public function testConstructorAssignsNullIndex(): void
    {
        $descriptor = new Descriptor(new NodeIterator([]), null);

        $this->assertNull($descriptor->index);
    }

    public function testConstructorAssignsIndexArray(): void
    {
        $node = new Node(1, 'Electronics');
        $index = [1 => $node];
        $descriptor = new Descriptor(new NodeIterator([]), $index);

        $this->assertSame($index, $descriptor->index);
    }
}
