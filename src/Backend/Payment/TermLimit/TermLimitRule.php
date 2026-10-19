<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Payment\TermLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\TermLimit;

use Comfino\Enum\LoanTypeInterface;

/**
 * Single rule binding product attributes (tag names or category IDs) to a product type, optionally with a term
 * constraint. Its effect depends on the {@see MatchMode} of the rule set that holds it.
 */
final class TermLimitRule
{
    /**
     * @param LoanTypeInterface $productType Product type the rule applies to
     * @param array<int|string> $attributes Tag names or category IDs; a product matches when it has any of them
     * @param TermConstraint|null $termConstraint Allowed terms (allow list: null = any term; block list: null = type blocked)
     */
    public function __construct(
        public readonly LoanTypeInterface $productType,
        public readonly array $attributes,
        public readonly ?TermConstraint $termConstraint = null
    ) {
    }

    /**
     * @return array{productType: string, attributes: array<int|string>, termConstraint: array<string, mixed>|null}
     */
    public function getAsArray(): array
    {
        return [
            'productType' => $this->productType->getValue(),
            'attributes' => $this->attributes,
            'termConstraint' => $this->termConstraint?->getAsArray(),
        ];
    }
}
