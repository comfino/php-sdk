<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment;

use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;

/**
 * Utility class for handling product type operations.
 */
final class ProductTypeTools
{
    /**
     * Converts raw product type strings to LoanTypeInterface values.
     *
     * Known types resolve to LoanType enum cases; types not yet defined in the SDK
     * resolve to UnknownLoanType flyweights so no values are silently dropped.
     *
     * @param string[] $productTypes
     *
     * @return LoanTypeInterface[]
     */
    public static function fromApiValues(array $productTypes): array
    {
        return array_map(
            static fn (string $productType): LoanTypeInterface => LoanType::fromApiValue($productType),
            $productTypes
        );
    }

    /**
     * Converts LoanTypeInterface values to their raw API string representations.
     *
     * Symmetric counterpart to {@see fromApiValues()}.
     *
     * @param LoanTypeInterface[] $productTypes
     *
     * @return string[]
     */
    public static function toApiValues(array $productTypes): array
    {
        return array_map(static fn (LoanTypeInterface $productType): string => $productType->getValue(), $productTypes);
    }

    /**
     * Converts raw product type strings to LoanType enum cases, silently discarding
     * any values not yet defined in this SDK version.
     *
     * Prefer {@see fromApiValues()} when you want to preserve unknown types.
     *
     * @param string[] $productTypes
     *
     * @return LoanType[]
     */
    public static function getAsEnums(array $productTypes): array
    {
        return array_values(array_filter(
            array_map(static fn (string $productType) => LoanType::tryFrom($productType), $productTypes)
        ));
    }
}
