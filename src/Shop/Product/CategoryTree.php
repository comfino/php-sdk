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

use Comfino\Shop\Product\CategoryTree\BuildStrategyInterface;
use Comfino\Shop\Product\CategoryTree\Node;
use Comfino\Shop\Product\CategoryTree\NodeIterator;

/**
 * Lazily loaded tree of product categories built via a pluggable build strategy for specific e-commerce platforms.
 * Maintains an ID-to-node index for fast lookups.
 */
final class CategoryTree
{
    /**
     * @var NodeIterator|null Lazily loaded root-level nodes of the category tree
     */
    private ?NodeIterator $nodes = null;

    /** @var Node[]|null Associative array mapping node IDs to nodes */
    private ?array $index = null;

    /**
     * @param BuildStrategyInterface $buildStrategy Strategy for building the category tree from e-commerce platform
     *                                              data loaded from the database
     */
    public function __construct(private readonly BuildStrategyInterface $buildStrategy)
    {
    }

    /**
     * Returns the root-level nodes, building the tree on first access.
     */
    public function getNodes(): NodeIterator
    {
        if ($this->nodes === null) {
            // Tree is not initialized - initialize it.
            $treeDescriptor = $this->buildStrategy->build();

            $this->nodes = $treeDescriptor->nodes;
            $this->index = $treeDescriptor->index;
        }

        return $this->nodes;
    }

    /**
     * Returns all node IDs in the tree (or subtree rooted at $rootNode).
     *
     * @return int[] Array of node IDs
     */
    public function getNodeIds(?Node $rootNode = null): array
    {
        if (!count($this->getNodes())) {
            // No nodes available in the tree.
            return [];
        }

        if ($rootNode === null && $this->index !== null) {
            // All nodes are already cached.
            return array_keys($this->index);
        }

        if ($rootNode === null) {
            // Return all node IDs in the tree.
            $nodeIds = array_map(static fn (Node $node): int => $node->getId(), iterator_to_array($this->nodes));
            $subNodeIds = [];

            foreach ($this->nodes as $node) {
                if ($node->hasChildren()) {
                    foreach ($node->getChildren() as $childNode) {
                        $subNodeIds[] = $this->getNodeIds($childNode);
                    }
                }
            }

            $nodeIds = array_merge($nodeIds, ...$subNodeIds);
        } else {
            // Return all node IDs in the subtree rooted at $rootNode.
            $nodeIds = [$rootNode->getId()];

            if ($rootNode->hasChildren()) {
                // Recursively traverse the subtree.
                $subNodeIds = [];

                foreach ($rootNode->getChildren() as $node) {
                    $subNodeIds[] = $this->getNodeIds($node);
                }

                $nodeIds = array_merge($nodeIds, ...$subNodeIds);
            }
        }

        return $nodeIds;
    }

    /**
     * Extracts node IDs from an iterator (e.g., a path-to-root result).
     *
     * @return int[] Array of node IDs
     */
    public function getPathNodeIds(NodeIterator $nodes): array
    {
        return array_map(static fn (Node $node): int => $node->getId(), iterator_to_array($nodes));
    }

    /**
     * Finds a node by ID, searching from the root or a given subtree node. Results are cached in the internal index.
     *
     * @return Node|null The found node or null if not found
     */
    public function getNodeById(int $id, ?Node $rootNode = null): ?Node
    {
        if ($this->index !== null && array_key_exists($id, $this->index)) {
            // Node is already cached.
            return $this->index[$id];
        }

        if ($this->index === null) {
            // Index is not initialized - initialize it.
            $this->index = [];
        }

        if ($rootNode === null) {
            // Search from the root.
            foreach ($this->getNodes() as $node) {
                $this->index[$node->getId()] = $node;

                if ($node->getId() === $id) {
                    // Found node - return it.
                    return $node;
                }
            }

            foreach ($this->getNodes() as $node) {
                if (($foundNode = $this->searchNodeChildren($id, $node)) !== null) {
                    // Found node in a deeper level - return it.
                    return $foundNode;
                }
            }
        } else {
            // Search from a given subtree node.
            $this->index[$rootNode->getId()] = $rootNode;

            if ($rootNode->getId() === $id) {
                // Found node - return it.
                return $rootNode;
            }

            if (($foundNode = $this->searchNodeChildren($id, $rootNode)) !== null) {
                // Found node in a deeper level - return it.
                return $foundNode;
            }
        }

        // Node isn't found - set index to null and return null.
        $this->index[$id] = null;

        return null;
    }

    /**
     * Indexes and searches direct children of $node for a node with the given ID, then recurses into deeper levels
     * via the getNodeById method.
     *
     * @param int $id The ID of the node to search for
     * @param Node $node The node to search children of
     *
     * @return Node|null The found node or null if not found
     */
    private function searchNodeChildren(int $id, Node $node): ?Node
    {
        if (!$node->hasChildren()) {
            // No children - return null.
            return null;
        }

        foreach ($node->getChildren() as $childNode) {
            $this->index[$childNode->getId()] = $childNode;

            if ($childNode->getId() === $id) {
                // Found node - return it.
                return $childNode;
            }
        }

        foreach ($node->getChildren() as $childNode) {
            if (($foundNode = $this->getNodeById($id, $childNode)) !== null) {
                // Found node in a deeper level - return it.
                return $foundNode;
            }
        }

        return null;
    }
}
