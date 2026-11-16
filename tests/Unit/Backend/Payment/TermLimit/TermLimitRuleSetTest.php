<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Payment\TermLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Payment\TermLimit;

use Comfino\Backend\Payment\TermLimit\CategoryMatcher;
use Comfino\Backend\Payment\TermLimit\MatchMode;
use Comfino\Backend\Payment\TermLimit\TagMatcher;
use Comfino\Backend\Payment\TermLimit\TermConstraint;
use Comfino\Backend\Payment\TermLimit\TermLimitRule;
use Comfino\Backend\Payment\TermLimit\TermLimitRuleSet;
use Comfino\Enum\LoanType;
use Comfino\Shop\Product\CategoryFilter;
use Comfino\Tests\Unit\Support\ProductFilteringFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Scenarios from the Shoper financial product filters specification: tags "raty0-12" and "raty0-24" grant 0%
 * installments up to 12 and 24 terms.
 */
final class TermLimitRuleSetTest extends TestCase
{
    use ProductFilteringFixtures;

    private const ZERO = LoanType::INSTALLMENTS_ZERO_PERCENT;

    public function testAllowListGrantsTypeWithMostRestrictiveCartLimit(): void
    {
        $resolution = $this->zeroPercentTagAllowList()->resolve(self::cart(self::product('A', ['raty0-24']), self::product('B', ['raty0-12'])));

        $this->assertFalse($resolution->isBlocked(self::ZERO));
        $this->assertSame(['maxTerm' => 12], $resolution->getConstraint(self::ZERO)?->getAsArray());
    }

    public function testAllowListUnitesRulesMatchedBySameProduct(): void
    {
        $resolution = $this->zeroPercentTagAllowList()->resolve(self::cart(self::product('A', ['raty0-12', 'raty0-24'])));

        $this->assertSame(['maxTerm' => 24], $resolution->getConstraint(self::ZERO)?->getAsArray());
    }

    public function testAllowListBlocksTypeWhenAnyProductIsNotTagged(): void
    {
        $resolution = $this->zeroPercentTagAllowList()->resolve(self::cart(self::product('A', ['raty0-24']), self::product('C')));

        $this->assertSame([self::ZERO->value], $resolution->getBlockedProductTypes());
        $this->assertNull($resolution->getAllowedProductsConfig());
    }

    public function testAllowListBlocksTypeWhenProductLimitsAreDisjoint(): void
    {
        $ruleSet = new TermLimitRuleSet(new TagMatcher(), MatchMode::ALLOW_LIST, [
            new TermLimitRule(self::ZERO, ['tag-a'], TermConstraint::of(terms: [3, 6])),
            new TermLimitRule(self::ZERO, ['tag-b'], TermConstraint::of(terms: [10, 12])),
        ]);

        $this->assertTrue($ruleSet->resolve(self::cart(self::product('A', ['tag-a']), self::product('B', ['tag-b'])))->isBlocked(self::ZERO));
    }

    public function testAllowListRuleWithoutConstraintGrantsAnyTerm(): void
    {
        $ruleSet = new TermLimitRuleSet(new TagMatcher(), MatchMode::ALLOW_LIST, [new TermLimitRule(self::ZERO, ['tag-a'])]);
        $resolution = $ruleSet->resolve(self::cart(self::product('A', ['tag-a'])));

        $this->assertFalse($resolution->isBlocked(self::ZERO));
        $this->assertNull($resolution->getAllowedProductsConfig());
    }

    public function testBlockListRuleWithoutConstraintBlocksType(): void
    {
        $ruleSet = new TermLimitRuleSet(new TagMatcher(), MatchMode::BLOCK_LIST, [new TermLimitRule(LoanType::PAY_LATER, ['no-paylater'])]);

        $this->assertTrue($ruleSet->resolve(self::cart(self::product('A'), self::product('B', ['no-paylater'])))->isBlocked(LoanType::PAY_LATER));
        $this->assertFalse($ruleSet->resolve(self::cart(self::product('A'), self::product('B', ['promo'])))->isBlocked(LoanType::PAY_LATER));
    }

    public function testBlockListRuleWithConstraintRestrictsMatchedProductsOnly(): void
    {
        $ruleSet = new TermLimitRuleSet(new TagMatcher(), MatchMode::BLOCK_LIST, [
            new TermLimitRule(self::ZERO, ['max10'], TermConstraint::of(maxTerm: 10)),
            new TermLimitRule(self::ZERO, ['min6'], TermConstraint::of(minTerm: 6)),
        ]);

        $this->assertNull($ruleSet->resolve(self::cart(self::product('A')))->getAllowedProductsConfig());
        $this->assertSame(
            ['minTerm' => 6, 'maxTerm' => 10],
            $ruleSet->resolve(self::cart(self::product('A', ['max10', 'min6'])))->getConstraint(self::ZERO)?->getAsArray()
        );
        $this->assertSame(
            ['minTerm' => 6, 'maxTerm' => 10],
            $ruleSet->resolve(self::cart(self::product('A', ['max10']), self::product('B', ['min6'])))->getConstraint(self::ZERO)?->getAsArray()
        );
    }

    public function testBlockListMaxTermOnlyRuleRestrictsMatchedProductsOnly(): void
    {
        $ruleSet = new TermLimitRuleSet(new TagMatcher(), MatchMode::BLOCK_LIST, [
            new TermLimitRule(self::ZERO, ['max10'], TermConstraint::of(maxTerm: 10)),
        ]);

        $this->assertNull($ruleSet->resolve(self::cart(self::product('A')))->getAllowedProductsConfig());

        $resolution = $ruleSet->resolve(self::cart(self::product('A', ['max10'])));
        $constraint = $resolution->getConstraint(self::ZERO);

        $this->assertFalse($resolution->isBlocked(self::ZERO));
        $this->assertSame(['maxTerm' => 10], $constraint?->getAsArray());
        $this->assertTrue($constraint->allows(1));
        $this->assertTrue($constraint->allows(10));
        $this->assertFalse($constraint->allows(11));
    }

    public function testTagMatcherCaseInsensitiveFlag(): void
    {
        $rules = [new TermLimitRule(self::ZERO, ['raty0-12'], TermConstraint::of(maxTerm: 12))];
        $cart = self::cart(self::product('A', ['Raty0-12']));

        $this->assertTrue((new TermLimitRuleSet(new TagMatcher(), MatchMode::ALLOW_LIST, $rules))->resolve($cart)->isBlocked(self::ZERO));
        $this->assertSame(
            ['maxTerm' => 12],
            (new TermLimitRuleSet(new TagMatcher(caseInsensitive: true), MatchMode::ALLOW_LIST, $rules))
                ->resolve($cart)
                ->getConstraint(self::ZERO)?->getAsArray()
        );
        $this->assertTrue((new TagMatcher(caseInsensitive: true))->isCaseInsensitive());
    }

    public function testCategoryAllowListMatchesSubcategories(): void
    {
        $ruleSet = new TermLimitRuleSet(new CategoryMatcher(new CategoryFilter(self::categoryTree())), MatchMode::ALLOW_LIST, [
            new TermLimitRule(self::ZERO, [10], TermConstraint::of(maxTerm: 20)),
        ]);

        $this->assertSame(
            ['maxTerm' => 20],
            $ruleSet->resolve(self::cart(self::product('A', categoryIds: [100])))->getConstraint(self::ZERO)?->getAsArray()
        );
        $this->assertTrue($ruleSet->resolve(self::cart(self::product('A', categoryIds: [11])))->isBlocked(self::ZERO));
    }

    public function testCategoryBlockListBlocksTypeForSubcategories(): void
    {
        $ruleSet = new TermLimitRuleSet(new CategoryMatcher(new CategoryFilter(self::categoryTree())), MatchMode::BLOCK_LIST, [
            new TermLimitRule(LoanType::PAY_LATER, [1]),
        ]);

        $this->assertTrue($ruleSet->resolve(self::cart(self::product('A', categoryIds: [2, 11])))->isBlocked(LoanType::PAY_LATER));
        $this->assertFalse($ruleSet->resolve(self::cart(self::product('A', categoryIds: [2])))->isBlocked(LoanType::PAY_LATER));
    }

    public function testTypesWithoutRulesAndEmptyCartAreNotRestricted(): void
    {
        $resolution = $this->zeroPercentTagAllowList()->resolve(self::cart());

        $this->assertFalse($resolution->isBlocked(self::ZERO));
        $this->assertFalse($resolution->isBlocked(LoanType::PAY_LATER));
        $this->assertNull($resolution->getAllowedProductsConfig());
    }

    public function testGetAsArray(): void
    {
        $this->assertSame(
            [
                'mode' => 'allow_list',
                'rules' => [
                    ['productType' => self::ZERO->value, 'attributes' => ['raty0-12'], 'termConstraint' => ['maxTerm' => 12]],
                    ['productType' => self::ZERO->value, 'attributes' => ['raty0-24'], 'termConstraint' => ['maxTerm' => 24]],
                ],
            ],
            $this->zeroPercentTagAllowList()->getAsArray()
        );
    }

    private function zeroPercentTagAllowList(): TermLimitRuleSet
    {
        return new TermLimitRuleSet(new TagMatcher(), MatchMode::ALLOW_LIST, [
            new TermLimitRule(self::ZERO, ['raty0-12'], TermConstraint::of(maxTerm: 12)),
            new TermLimitRule(self::ZERO, ['raty0-24'], TermConstraint::of(maxTerm: 24)),
        ]);
    }
}
