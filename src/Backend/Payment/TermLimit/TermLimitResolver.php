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

use Comfino\Api\Dto\Payment\AllowedProductConfig;
use Comfino\Backend\Payment\AllowedProductsConfigBuilder;
use Comfino\Shop\Cart;
use WeakMap;

/**
 * Resolves the effective term limits of a cart by merging the merchant's global allowedProductsConfig with any number
 * of rule sets (e.g. tag allow list + category block list). Results are memoized per cart instance, so the same resolver
 * can back {@see \Comfino\Backend\Payment\Filter\FilterByTermLimits} and the allowedProductsConfig of the order request
 * without evaluating the rules twice.
 */
final class TermLimitResolver
{
    /** @var WeakMap<Cart, TermLimitResolution> */
    private WeakMap $cache;

    /**
     * @param TermLimitRuleSet[] $ruleSets Rule sets to merge
     * @param AllowedProductConfig[]|null $allowedProductsConfig Global term limits (e.g. from AllowedProductsConfigBuilder)
     */
    public function __construct(private readonly array $ruleSets = [], private readonly ?array $allowedProductsConfig = null)
    {
        $this->cache = new WeakMap();
    }

    public function resolve(Cart $cart): TermLimitResolution
    {
        if (isset($this->cache[$cart])) {
            return $this->cache[$cart];
        }

        $resolution = TermLimitResolution::fromAllowedProductsConfig($this->allowedProductsConfig);

        foreach ($this->ruleSets as $ruleSet) {
            $resolution = $resolution->merge($ruleSet->resolve($cart));
        }

        return $this->cache[$cart] = $resolution;
    }

    /**
     * Shortcut for the allowedProductsConfig to send with the order/paywall request (null = no constraints).
     *
     * @return AllowedProductConfig[]|null
     */
    public function getAllowedProductsConfig(Cart $cart): ?array
    {
        return $this->resolve($cart)->getAllowedProductsConfig();
    }

    /**
     * @return array{allowedProductsConfig: array<int, array<string, mixed>>|null, ruleSets: array<int, array<string, mixed>>}
     */
    public function getAsArray(): array
    {
        return [
            'allowedProductsConfig' => AllowedProductsConfigBuilder::toFrontendArray($this->allowedProductsConfig),
            'ruleSets' => array_map(static fn (TermLimitRuleSet $ruleSet): array => $ruleSet->getAsArray(), $this->ruleSets),
        ];
    }
}
