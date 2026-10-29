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
use Comfino\Backend\Payment\TermLimit\TermLimitResolver;
use Comfino\Shop\Cart;

/**
 * Filter by term limits - removes product types that the resolved term limits block for the cart (ineligible products
 * in an allow-list rule set, block-list rules without a term constraint, or an unsatisfiable merged constraint).
 * Use the same resolver for the order request's allowedProductsConfig to keep both consistent.
 */
class FilterByTermLimits implements ProductTypeFilterInterface
{
    public function __construct(private readonly TermLimitResolver $termLimitResolver)
    {
    }

    /** @inheritDoc */
    public function getAllowedProductTypes(array $availableProductTypes, Cart $cart): array
    {
        return $this->termLimitResolver->resolve($cart)->filterProductTypes($availableProductTypes);
    }

    /** @return array<string, mixed> */
    public function getAsArray(): array
    {
        return ['termLimitResolver' => $this->termLimitResolver->getAsArray()];
    }
}
