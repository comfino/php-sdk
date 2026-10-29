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

use Comfino\Api\Dto\Payment\AllowedProductConfig;
use Comfino\Backend\Payment\Filter\FilterByCartValueLowerLimit;
use Comfino\Backend\Payment\Filter\FilterByTermLimits;
use Comfino\Backend\Payment\ProductTypeFilterManager;
use Comfino\Backend\Payment\TermLimit\MatchMode;
use Comfino\Backend\Payment\TermLimit\ProductMatcherInterface;
use Comfino\Backend\Payment\TermLimit\TagMatcher;
use Comfino\Backend\Payment\TermLimit\TermConstraint;
use Comfino\Backend\Payment\TermLimit\TermLimitResolver;
use Comfino\Backend\Payment\TermLimit\TermLimitRule;
use Comfino\Backend\Payment\TermLimit\TermLimitRuleSet;
use Comfino\Enum\LoanType;
use Comfino\Tests\Unit\Support\ProductFilteringFixtures;
use PHPUnit\Framework\TestCase;

final class TermLimitResolverTest extends TestCase
{
    use ProductFilteringFixtures;

    private const ZERO = LoanType::INSTALLMENTS_ZERO_PERCENT;
    private const AVAILABLE = [LoanType::INSTALLMENTS_ZERO_PERCENT, LoanType::CONVENIENT_INSTALLMENTS, LoanType::PAY_LATER];

    protected function tearDown(): void
    {
        ProductTypeFilterManager::reset();
    }

    public function testMergesGlobalConfigWithAllowAndBlockRuleSets(): void
    {
        $resolver = new TermLimitResolver(
            [
                new TermLimitRuleSet(new TagMatcher(), MatchMode::ALLOW_LIST, [new TermLimitRule(self::ZERO, ['raty0-24'], TermConstraint::of(maxTerm: 24))]),
                new TermLimitRuleSet(new TagMatcher(), MatchMode::BLOCK_LIST, [new TermLimitRule(LoanType::PAY_LATER, ['no-paylater'])]),
            ],
            [new AllowedProductConfig(self::ZERO, 18, 3), new AllowedProductConfig(LoanType::CONVENIENT_INSTALLMENTS, 48)]
        );

        $resolution = $resolver->resolve(self::cart(self::product('A', ['raty0-24', 'no-paylater'])));

        $this->assertSame(['PAY_LATER'], $resolution->getBlockedProductTypes());
        $this->assertSame(
            ['INSTALLMENTS_ZERO_PERCENT' => ['minTerm' => 3, 'maxTerm' => 18], 'CONVENIENT_INSTALLMENTS' => ['maxTerm' => 48]],
            $resolution->getAsArray()['constraints']
        );
        $this->assertCount(2, $resolver->getAllowedProductsConfig(self::cart(self::product('A', ['raty0-24']))) ?? []);
    }

    public function testResolutionIsMemoizedPerCart(): void
    {
        $matcher = $this->createMock(ProductMatcherInterface::class);
        $matcher->expects($this->once())->method('matches')->willReturn(true);

        $resolver = new TermLimitResolver([new TermLimitRuleSet($matcher, MatchMode::ALLOW_LIST, [new TermLimitRule(self::ZERO, ['tag-a'])])]);
        $cart = self::cart(self::product('A', ['tag-a']));

        $this->assertSame($resolver->resolve($cart), $resolver->resolve($cart));
    }

    public function testFilterByTermLimitsWorksInsideFilterManager(): void
    {
        $resolver = new TermLimitResolver([
            new TermLimitRuleSet(new TagMatcher(), MatchMode::ALLOW_LIST, [new TermLimitRule(self::ZERO, ['raty0-12'], TermConstraint::of(maxTerm: 12))]),
        ]);

        $manager = ProductTypeFilterManager::getInstance('shop:1');
        $manager->addFilter(new FilterByTermLimits($resolver));
        $manager->addFilter(new FilterByCartValueLowerLimit([LoanType::PAY_LATER->value => 50000]));

        $this->assertSame(
            [self::ZERO, LoanType::CONVENIENT_INSTALLMENTS],
            $manager->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('A', ['raty0-12'])))
        );
        $this->assertSame(
            [LoanType::CONVENIENT_INSTALLMENTS],
            $manager->getAllowedProductTypes(self::AVAILABLE, self::cart(self::product('A', ['raty0-12']), self::product('B')))
        );
        $this->assertArrayHasKey('termLimitResolver', (new FilterByTermLimits($resolver))->getAsArray());
    }
}
