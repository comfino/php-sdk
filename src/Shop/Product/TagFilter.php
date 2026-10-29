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

use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\ProductInterface;

/**
 * Matches cart products against tag name lists, in both block-list (excluded tags) and opt-in (required tags) modes.
 *
 * Tags are flat (no hierarchy) and identified by name; names are trimmed and compared exactly, or case-insensitively
 * (multibyte-aware, e.g. "RATY-ŁÓDŹ" matches "raty-łódź") when the filter is created with $caseInsensitive = true.
 * Products without tag data - not implementing {@see TaggedProductInterface}, or returning null - are treated as
 * untagged: they always pass a block list and never pass an opt-in list.
 */
class TagFilter
{
    /**
     * @param bool $caseInsensitive Whether tag names are compared case-insensitively (default: exact match)
     */
    public function __construct(private readonly bool $caseInsensitive = false)
    {
    }

    public function isCaseInsensitive(): bool
    {
        return $this->caseInsensitive;
    }

    /**
     * Normalizes a tag name list: trims names, drops empty ones and duplicates, and reindexes.
     *
     * @param array<mixed> $tags Raw tag names
     *
     * @return string[]
     */
    public static function normalizeTags(array $tags): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($tag): string => trim(strip_tags((string) $tag)), $tags),
            static fn (string $tag): bool => $tag !== ''
        )));
    }

    /**
     * Returns the tag names of a product, or an empty array when the product carries no tag data.
     *
     * @return string[]
     */
    public function getProductTags(ProductInterface $product): array
    {
        return $product instanceof TaggedProductInterface ? self::normalizeTags($product->getTags() ?? []) : [];
    }

    /**
     * Checks if a product carries none of the excluded tags.
     *
     * @param string[] $excludedTags
     */
    public function isProductAvailable(ProductInterface $product, array $excludedTags): bool
    {
        return array_intersect($this->toMatchKeys($this->getProductTags($product)), $this->toMatchKeys($excludedTags)) === [];
    }

    /**
     * Checks if a product carries at least one of the required tags.
     *
     * @param string[] $requiredTags
     */
    public function isProductEligible(ProductInterface $product, array $requiredTags): bool
    {
        return array_intersect($this->toMatchKeys($this->getProductTags($product)), $this->toMatchKeys($requiredTags)) !== [];
    }

    /**
     * Block list: checks if no cart product carries any of the excluded tags. An empty list or cart is always valid.
     *
     * @param string[] $excludedTags
     */
    public function isCartValid(Cart $cart, array $excludedTags): bool
    {
        if (empty($excludedTags) || empty($cart->getCartItems())) {
            return true;
        }

        return array_intersect($this->toMatchKeys($cart->getCartTags()), $this->toMatchKeys($excludedTags)) === [];
    }

    /**
     * Opt-in list: checks if every cart product carries at least one of the required tags. An empty list means "no
     * requirement" and an empty cart is vacuously eligible.
     *
     * @param string[] $requiredTags
     */
    public function isCartEligible(Cart $cart, array $requiredTags): bool
    {
        if (empty($requiredTags)) {
            return true;
        }

        foreach ($cart->getCartItems() as $cartItem) {
            if (!$this->isProductEligible($cartItem->getProduct(), $requiredTags)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Converts tag names into the keys they are compared by (normalized, lowercased in case-insensitive mode).
     *
     * @param array<mixed> $tags
     *
     * @return string[]
     */
    private function toMatchKeys(array $tags): array
    {
        $tags = self::normalizeTags($tags);

        return $this->caseInsensitive ? array_map(static fn (string $tag): string => mb_strtolower($tag, 'UTF-8'), $tags) : $tags;
    }
}
