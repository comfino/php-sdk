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
 * Descriptor for a category tree, containing root-level nodes and an index mapping category IDs to nodes.
 */
final class Descriptor
{
    /**
     * @param NodeIterator $nodes Iterator over root-level nodes of the category tree
     * @param Node[]|null $index Associative array mapping category IDs to nodes
     */
    public function __construct(public readonly NodeIterator $nodes, public readonly ?array $index)
    {
    }
}
