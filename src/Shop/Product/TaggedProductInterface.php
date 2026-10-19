<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Shop\Product
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Product;

/**
 * Optional capability of a cart product: the shop-side tags assigned to it (e.g. Shoper product tags, WooCommerce product_tag terms).
 *
 * Tags are identified by their names, not by numeric IDs: unlike category names, tag names are short single tokens without
 * spaces (e.g. "raty0-12"), so they are stable identifiers that the backend filters and the frontend SDK can share directly.
 * Names are compared exactly (case-sensitive) after trimming.
 *
 * Kept separate from the api-client ProductInterface on purpose: tags are used only by SDK and frontend filtering, never
 * sent to the ComfinoPay API, and widening the wire-format interface would break every plugin implementing it. Products
 * that do not implement this interface are treated as having no tags.
 */
interface TaggedProductInterface
{
    /**
     * Returns the names of the shop tags assigned to the product.
     *
     * @return string[]|null Tag names, or null when the platform does not provide tag data
     */
    public function getTags(): ?array;
}
