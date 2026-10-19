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

use Comfino\Shop\Order\Cart\ProductInterface;
use Comfino\Shop\Product\TagFilter;

/**
 * Matches products carrying any of the given tag names.
 */
final class TagMatcher implements ProductMatcherInterface
{
    private readonly TagFilter $tagFilter;

    /**
     * @param bool $caseInsensitive Whether tag names are compared case-insensitively (default: exact match)
     */
    public function __construct(bool $caseInsensitive = false)
    {
        $this->tagFilter = new TagFilter($caseInsensitive);
    }

    public function isCaseInsensitive(): bool
    {
        return $this->tagFilter->isCaseInsensitive();
    }

    /**
     * @param array<int|string> $attributes Tag names
     */
    public function matches(ProductInterface $product, array $attributes): bool
    {
        return $this->tagFilter->isProductEligible($product, array_map('strval', $attributes));
    }
}
