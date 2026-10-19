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

use Comfino\Shop\Cart;

/**
 * Set of term-limit rules of one kind (one matcher) and one {@see MatchMode}, resolved against a cart.
 *
 * Per ruled product type, every cart product yields a product-level constraint (union of matched rules in
 * {@see MatchMode::ALLOW_LIST}, intersection in {@see MatchMode::BLOCK_LIST}) and the cart-level constraint is the
 * intersection over all products. The type is blocked when a product is not eligible or the result is
 * unsatisfiable. Types without rules are left untouched; an empty cart is never restricted.
 */
final class TermLimitRuleSet
{
    /** @var array<string, TermLimitRule[]> */
    private readonly array $rulesByProductType;

    /**
     * @param ProductMatcherInterface $matcher Attribute matcher (tags, categories, ...)
     * @param MatchMode $mode Rule interpretation
     * @param TermLimitRule[] $rules Rules
     */
    public function __construct(
        private readonly ProductMatcherInterface $matcher,
        private readonly MatchMode $mode,
        private readonly array $rules
    ) {
        $rulesByProductType = [];

        foreach ($rules as $rule) {
            $rulesByProductType[$rule->productType->getValue()][] = $rule;
        }

        $this->rulesByProductType = $rulesByProductType;
    }

    public function getMode(): MatchMode
    {
        return $this->mode;
    }

    public function resolve(Cart $cart): TermLimitResolution
    {
        $resolution = TermLimitResolution::empty();

        foreach ($this->rulesByProductType as $rules) {
            $productType = $rules[0]->productType;
            $cartConstraint = $this->resolveCartConstraint($cart, $rules);

            $resolution = $cartConstraint === null
                ? $resolution->withBlocked($productType)
                : $resolution->withConstraint($productType, $cartConstraint);
        }

        return $resolution;
    }

    /**
     * @return array{mode: string, rules: array<int, array<string, mixed>>}
     */
    public function getAsArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'rules' => array_map(static fn (TermLimitRule $rule): array => $rule->getAsArray(), $this->rules),
        ];
    }

    /**
     * @param TermLimitRule[] $rules Rules of a single product type
     *
     * @return TermConstraint|null Cart-level constraint, or null when the type is blocked
     */
    private function resolveCartConstraint(Cart $cart, array $rules): ?TermConstraint
    {
        $cartConstraint = TermConstraint::unconstrained();

        foreach ($cart->getCartItems() as $cartItem) {
            $matchedRules = array_filter(
                $rules,
                fn (TermLimitRule $rule): bool => $this->matcher->matches($cartItem->getProduct(), $rule->attributes)
            );

            if (($productConstraint = $this->resolveProductConstraint($matchedRules)) === null) {
                return null;
            }

            $cartConstraint = $cartConstraint->intersect($productConstraint);

            if (!$cartConstraint->isSatisfiable()) {
                return null;
            }
        }

        return $cartConstraint;
    }

    /**
     * @param TermLimitRule[] $matchedRules Rules matched by a single product
     *
     * @return TermConstraint|null Product-level constraint, or null when the type is blocked for the product
     */
    private function resolveProductConstraint(array $matchedRules): ?TermConstraint
    {
        if ($this->mode === MatchMode::ALLOW_LIST) {
            if ($matchedRules === []) {
                return null;
            }

            $productConstraint = null;

            foreach ($matchedRules as $rule) {
                $ruleConstraint = $rule->termConstraint ?? TermConstraint::unconstrained();
                $productConstraint = $productConstraint === null ? $ruleConstraint : $productConstraint->union($ruleConstraint);
            }

            return $productConstraint;
        }

        $productConstraint = TermConstraint::unconstrained();

        foreach ($matchedRules as $rule) {
            if ($rule->termConstraint === null) {
                return null;
            }

            $productConstraint = $productConstraint->intersect($rule->termConstraint);
        }

        return $productConstraint;
    }
}
