<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Product
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Product;

/**
 * Value object representing a product category with hierarchical structure.
 */
final class Category
{
    /**
     * @param int $id Unique category identifier from the e-commerce platform
     * @param string $name Display name of the category
     * @param int $position Sort order within parent category (lower numbers first)
     * @param Category[] $children Array of child categories (empty for leaf categories)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $position,
        public readonly array $children
    ) {
    }
}
