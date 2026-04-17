<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Product\CategoryTree
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Product\CategoryTree;

/**
 * A node in the category tree. Holds a reference to its parent and children, enabling
 * ancestor/descendant traversal and path-to-root resolution.
 */
final class Node
{
    /**
     * @param int $id Category ID
     * @param string $name Category name
     * @param Node|null $parent Parent node (null for root nodes)
     * @param NodeIterator|null $children Child nodes iterator
     */
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private ?self $parent = null,
        private ?NodeIterator $children = null
    ) {
    }

    /**
     * Returns the category ID.
     *
     * @return int Category ID
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Returns the category name.
     *
     * @return string Category name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the parent node.
     *
     * @return Node|null Parent node
     */
    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Sets the parent node.
     *
     * @param Node|null $parent Parent node
     */
    public function setParent(?self $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * Returns the child nodes iterator.
     *
     * @return NodeIterator|null
     */
    public function getChildren(): ?NodeIterator
    {
        return $this->children;
    }

    /**
     * Sets the child nodes iterator.
     *
     * @param NodeIterator $children Child nodes iterator
     */
    public function setChildren(NodeIterator $children): void
    {
        $this->children = $children;
    }

    /**
     * Returns true if this node has no parent.
     *
     * @return bool True if this node is the root node, false otherwise
     */
    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    /**
     * Returns true if this node has no children.
     *
     * @return bool True if this node is a leaf node, false otherwise
     */
    public function isLeaf(): bool
    {
        return !$this->hasChildren();
    }

    /**
     * Returns true if this node is the direct parent of $node.
     *
     * @param Node $node Node to check
     *
     * @return bool True if this node is the parent of $node, false otherwise
     */
    public function isParentOf(self $node): bool
    {
        return $this === $node->getParent();
    }

    /**
     * Returns true if $node is the direct parent of this node.
     *
     * @param Node $node Node to check
     *
     * @return bool True if this node is the parent of $node, false otherwise
     */
    public function isChildOf(self $node): bool
    {
        return $node === $this->parent;
    }

    /**
     * Returns true if this node is a direct or indirect ancestor of $node.
     *
     * @param Node $node Node to check
     *
     * @return bool True if this node is a direct or indirect ancestor of $node, false otherwise
     */
    public function isAncestorOf(self $node): bool
    {
        if ($this->isParentOf($node)) {
            return true;
        }

        if ($this->isLeaf()) {
            return false;
        }

        foreach ($this->children as $childNode) {
            if ($childNode->isAncestorOf($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if this node is a direct or indirect descendant of $node.
     *
     * @param Node $node Node to check
     *
     * @return bool True if this node is a direct or indirect descendant of $node, false otherwise
     */
    public function isDescendantOf(self $node): bool
    {
        if ($this->isChildOf($node)) {
            return true;
        }

        if ($this->isRoot()) {
            return false;
        }

        $parentNode = $this->parent;

        while ($parentNode !== null) {
            if ($parentNode === $node) {
                return true;
            }

            $parentNode = $parentNode->getParent();
        }

        return false;
    }

    /**
     * Returns true if this node has at least one child.
     *
     * @return bool True if this node has at least one child, false otherwise
     */
    public function hasChildren(): bool
    {
        return $this->children !== null && $this->children->count() !== 0;
    }

    /**
     * Returns an iterator over all nodes from this node up to the root (inclusive).
     *
     * @return NodeIterator Iterator over all nodes from this node up to the root (inclusive)
     */
    public function getPathToRoot(): NodeIterator
    {
        $nodes = [];
        $node = $this;

        do {
            $nodes[] = $node;
        } while (($node = $node->getParent()) !== null);

        return new NodeIterator($nodes);
    }
}
