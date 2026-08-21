<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Payment\Filter
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\Filter;

use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Shop\Cart;

/**
 * Filter by cart value lower limit for product types - allows only products with a value limit lower or equal to
 * the cart value.
 */
class FilterByCartValueLowerLimit implements ProductTypeFilterInterface
{
    /**
     * @param int[] $cartValueLimitsByProductType List of product types and their associated cart value limits
     *                                            as ['PRODUCT_TYPE' => cart_value_limit]
     */
    public function __construct(private readonly array $cartValueLimitsByProductType)
    {
    }

    /** @inheritDoc */
    public function getAllowedProductTypes(array $availableProductTypes, Cart $cart): array
    {
        $allowedProductTypes = [];

        foreach ($availableProductTypes as $productType) {
            if (array_key_exists($productType->getValue(), $this->cartValueLimitsByProductType)) {
                if ($cart->getTotalValue() >= $this->cartValueLimitsByProductType[$productType->getValue()]) {
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
        return ['cartValueLimitsByProductType' => $this->cartValueLimitsByProductType];
    }
}
