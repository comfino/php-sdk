<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Product
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Product;

use Comfino\Shop\Product\CategoryTree\Descriptor;
use Comfino\Shop\Product\CategoryTree\Node;
use Comfino\Shop\Product\CategoryTree\NodeIterator;

/**
 * Builds a category tree structure from a flat or nested array of Category objects.
 */
class CategoryManager
{
    /**
     * Builds a tree descriptor from root-level Category objects with nested children.
     *
     * @param Category[] $nestedCategories Root-level categories with nested children
     */
    public static function buildCategoryTree(array $nestedCategories): Descriptor
    {
        $nodes = [];
        $index = [];

        foreach ($nestedCategories as $category) {
            $node = new Node($category->id, $category->name);

            if (!empty($category->children)) {
                $childNodes = [];

                foreach ($category->children as $childCategory) {
                    $childNodes[] = self::processCategory($node, $childCategory, $index);
                }

                $node->setChildren(new NodeIterator($childNodes));
            }

            $nodes[] = $node;
            $index[$node->getId()] = $node;
        }

        return new Descriptor(new NodeIterator($nodes), $index);
    }

    /**
     * Recursively builds a Node for the given category and its children.
     *
     * @param array<int, Node> $index
     */
    private static function processCategory(Node $parentNode, Category $category, array &$index): Node
    {
        $node = new Node($category->id, $category->name, $parentNode);

        if (!empty($category->children)) {
            $childNodes = [];

            foreach ($category->children as $childCategory) {
                $childNodes[] = self::processCategory($node, $childCategory, $index);
            }

            $node->setChildren(new NodeIterator($childNodes));
        }

        $index[$node->getId()] = $node;

        return $node;
    }
}
