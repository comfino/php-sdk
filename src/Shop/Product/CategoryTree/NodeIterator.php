<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Product\CategoryTree
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Product\CategoryTree;

/**
 * Iterator and countable collection of category tree nodes.
 *
 * @implements \Iterator<int, Node>
 */
class NodeIterator implements \Iterator, \Countable
{
    /**
     * @param Node[] $nodes Array of category tree nodes
     */
    public function __construct(private array $nodes)
    {
    }

    public function current(): Node
    {
        return current($this->nodes);
    }

    public function next(): void
    {
        next($this->nodes);
    }

    public function key(): int
    {
        return key($this->nodes);
    }

    public function valid(): bool
    {
        return key($this->nodes) !== null;
    }

    public function rewind(): void
    {
        reset($this->nodes);
    }

    public function count(): int
    {
        return count($this->nodes);
    }
}
