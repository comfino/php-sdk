<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Payment\Filter
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\Filter;

use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Shop\Cart;
use Comfino\Shop\Product\TagFilter;

/**
 * Filter by excluded tags for product types (block list) - removes a product type when any cart product carries one
 * of the tags excluded for that type.
 */
class FilterByExcludedTag implements ProductTypeFilterInterface
{
    private readonly TagFilter $tagFilter;

    /**
     * @param string[][] $excludedTagsByProductType List of excluded tag names by product type ['PRODUCT_TYPE' => ['tag-name', ...]]
     * @param bool $caseInsensitive Whether tag names are compared case-insensitively (default: exact match)
     */
    public function __construct(private readonly array $excludedTagsByProductType, private readonly bool $caseInsensitive = false)
    {
        $this->tagFilter = new TagFilter($caseInsensitive);
    }

    /** @inheritDoc */
    public function getAllowedProductTypes(array $availableProductTypes, Cart $cart): array
    {
        $allowedProductTypes = [];

        foreach ($availableProductTypes as $productType) {
            if (array_key_exists($productType->getValue(), $this->excludedTagsByProductType)) {
                if ($this->tagFilter->isCartValid($cart, $this->excludedTagsByProductType[$productType->getValue()])) {
                    $allowedProductTypes[] = $productType;
                }
            } else {
                $allowedProductTypes[] = $productType;
            }
        }

        return $allowedProductTypes;
    }

    /** @return array<string, mixed> */
    public function getAsArray(): array
    {
        return ['excludedTagsByProductType' => $this->excludedTagsByProductType, 'caseInsensitive' => $this->caseInsensitive];
    }
}
