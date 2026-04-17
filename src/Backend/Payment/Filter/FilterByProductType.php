<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Payment\Filter
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\Filter;

use Comfino\Backend\Payment\ProductTypeFilterInterface;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Shop\Cart;

/**
 * Filter by product type - allows only products of the specified types.
 */
class FilterByProductType implements ProductTypeFilterInterface
{
    /**
     * @param LoanTypeInterface[] $allowedProductTypes List of allowed product types
     */
    public function __construct(private readonly array $allowedProductTypes)
    {
    }

    /** @inheritDoc */
    public function getAllowedProductTypes(array $availableProductTypes, Cart $cart): array
    {
        return array_values(array_filter(
            $availableProductTypes,
            fn (LoanTypeInterface $type): bool => in_array(
                $type->getValue(),
                array_map(static fn (LoanTypeInterface $type): string => $type->getValue(), $this->allowedProductTypes),
                true
            )
        ));
    }

    /** @return array<string, mixed> */
    public function getAsArray(): array
    {
        return ['allowedProductTypes' => $this->allowedProductTypes];
    }
}
