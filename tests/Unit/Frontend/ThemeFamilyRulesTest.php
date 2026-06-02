<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Frontend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Frontend;

use Comfino\Frontend\ThemeFamilyRules;
use PHPUnit\Framework\TestCase;

final class ThemeFamilyRulesTest extends TestCase
{
    public function testResolveFamilyReturnsFamilyWhenPredicateMatches(): void
    {
        $rules = new ThemeFamilyRules();
        $rules->register('hyva', static fn(array $chain): bool => in_array('hyva/default', $chain, true));

        $this->assertSame('hyva', $rules->resolveFamily(['Hyva/default', 'Magento/blank']));
    }

    public function testResolveFamilyLowercasesChainBeforeMatching(): void
    {
        $rules = new ThemeFamilyRules();
        $rules->register('hyva', static fn(array $chain): bool => in_array('hyva/default', $chain, true));

        /* Chain passed in mixed case — should still match after lowercasing. */
        $this->assertSame('hyva', $rules->resolveFamily(['Hyva/Default', 'Magento/Blank']));
    }

    public function testResolveFamilyReturnsCustomWhenNoRulesRegistered(): void
    {
        $this->assertSame('custom', (new ThemeFamilyRules())->resolveFamily(['Magento/blank']));
    }

    public function testResolveFamilyReturnsCustomWhenNothingMatches(): void
    {
        $rules = new ThemeFamilyRules();
        $rules->register('hyva', static fn(array $chain): bool => in_array('hyva/default', $chain, true));

        $this->assertSame('custom', $rules->resolveFamily(['Magento/blank', 'Magento/base']));
    }

    public function testFirstMatchingRuleWins(): void
    {
        $rules = new ThemeFamilyRules();
        $rules->register('luma', static fn(array $chain): bool => in_array('magento/luma', $chain, true));
        $rules->register('blank', static fn(array $chain): bool => in_array('magento/blank', $chain, true));

        /* Chain contains both 'luma' and 'blank' — 'luma' was registered first, so it wins. */
        $this->assertSame('luma', $rules->resolveFamily(['Magento/luma', 'Magento/blank']));
    }

    public function testRegisterOverwritesPreviousRuleForSameFamily(): void
    {
        $rules = new ThemeFamilyRules();
        $rules->register('luma', static fn(array $chain): bool => false);
        $rules->register('luma', static fn(array $chain): bool => true);

        $this->assertSame('luma', $rules->resolveFamily([]));
    }

    public function testResolveFamilyWithEmptyChain(): void
    {
        $rules = new ThemeFamilyRules();
        $rules->register('luma', static fn(array $chain): bool => !empty($chain));

        $this->assertSame('custom', $rules->resolveFamily([]));
    }
}
