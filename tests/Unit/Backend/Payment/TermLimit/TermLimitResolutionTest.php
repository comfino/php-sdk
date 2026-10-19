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
use Comfino\Backend\Payment\TermLimit\TermConstraint;
use Comfino\Backend\Payment\TermLimit\TermLimitResolution;
use Comfino\Enum\LoanType;
use PHPUnit\Framework\TestCase;

final class TermLimitResolutionTest extends TestCase
{
    public function testEmptyResolutionRestrictsNothing(): void
    {
        $resolution = TermLimitResolution::empty();

        $this->assertNull($resolution->getAllowedProductsConfig());
        $this->assertSame([], $resolution->getBlockedProductTypes());
        $this->assertTrue($resolution->getConstraint(LoanType::PAY_LATER)?->isUnconstrained());
    }

    public function testFromAllowedProductsConfigIntersectsDuplicateEntries(): void
    {
        $resolution = TermLimitResolution::fromAllowedProductsConfig([
            new AllowedProductConfig(LoanType::INSTALLMENTS_ZERO_PERCENT, 24),
            new AllowedProductConfig(LoanType::INSTALLMENTS_ZERO_PERCENT, null, 6),
            new AllowedProductConfig(LoanType::PAY_LATER, null, null, [1]),
        ]);

        $this->assertSame(
            ['INSTALLMENTS_ZERO_PERCENT' => ['minTerm' => 6, 'maxTerm' => 24], 'PAY_LATER' => ['terms' => [1]]],
            $resolution->getAsArray()['constraints']
        );
        $this->assertCount(2, $resolution->getAllowedProductsConfig() ?? []);
    }

    public function testMergeIntersectsConstraintsAndBlocksUnsatisfiableTypes(): void
    {
        $global = TermLimitResolution::empty()
            ->withConstraint(LoanType::INSTALLMENTS_ZERO_PERCENT, TermConstraint::of(maxTerm: 24))
            ->withConstraint(LoanType::PAY_LATER, TermConstraint::of(terms: [1]));
        $rules = TermLimitResolution::empty()
            ->withConstraint(LoanType::INSTALLMENTS_ZERO_PERCENT, TermConstraint::of(maxTerm: 12))
            ->withConstraint(LoanType::PAY_LATER, TermConstraint::of(minTerm: 3))
            ->withBlocked(LoanType::CONVENIENT_INSTALLMENTS);

        $merged = $global->merge($rules);

        $this->assertSame(['maxTerm' => 12], $merged->getConstraint(LoanType::INSTALLMENTS_ZERO_PERCENT)?->getAsArray());
        $this->assertEqualsCanonicalizing(['PAY_LATER', 'CONVENIENT_INSTALLMENTS'], $merged->getBlockedProductTypes());
        $this->assertNull($merged->getConstraint('PAY_LATER'));
        $this->assertSame(
            [LoanType::INSTALLMENTS_ZERO_PERCENT, LoanType::COMPANY_BNPL],
            $merged->filterProductTypes([LoanType::INSTALLMENTS_ZERO_PERCENT, LoanType::PAY_LATER, LoanType::CONVENIENT_INSTALLMENTS, LoanType::COMPANY_BNPL])
        );

        $config = $merged->getAllowedProductsConfig();

        $this->assertCount(1, $config ?? []);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $config[0]->type);
        $this->assertSame(12, $config[0]->maxTerm);
    }

    public function testBlockedTypeIgnoresLaterConstraints(): void
    {
        $resolution = TermLimitResolution::empty()
            ->withBlocked(LoanType::PAY_LATER)
            ->withConstraint(LoanType::PAY_LATER, TermConstraint::of(maxTerm: 3));

        $this->assertTrue($resolution->isBlocked(LoanType::PAY_LATER));
        $this->assertNull($resolution->getAllowedProductsConfig());
    }
}
