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
use Comfino\Enum\LoanType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TermConstraintTest extends TestCase
{
    public function testWhitelistAbsorbsBoundsAndIsCanonicalized(): void
    {
        $constraint = TermConstraint::of(3, 12, [24, 6, 3, 6, 1]);

        $this->assertNull($constraint->minTerm);
        $this->assertNull($constraint->maxTerm);
        $this->assertSame([3, 6], $constraint->terms);
    }

    public function testRejectsNonPositiveTerms(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TermConstraint::of(0);
    }

    public function testSatisfiabilityAndAllows(): void
    {
        $this->assertTrue(TermConstraint::unconstrained()->isUnconstrained());
        $this->assertFalse(TermConstraint::of(12, 6)->isSatisfiable());
        $this->assertFalse(TermConstraint::of(terms: [])->isSatisfiable());
        $this->assertTrue(TermConstraint::of(maxTerm: 12)->allows(12));
        $this->assertFalse(TermConstraint::of(maxTerm: 12)->allows(13));
        $this->assertTrue(TermConstraint::of(terms: [3, 6])->allows(6));
        $this->assertFalse(TermConstraint::of(terms: [3, 6])->allows(4));
    }

    /**
     * @return iterable<string, array{TermConstraint, TermConstraint, array<string, mixed>, bool}>
     */
    public static function intersectProvider(): iterable
    {
        yield 'ranges' => [TermConstraint::of(3, 24), TermConstraint::of(6, 12), ['minTerm' => 6, 'maxTerm' => 12], true];
        yield 'open sides' => [TermConstraint::of(minTerm: 6), TermConstraint::of(maxTerm: 12), ['minTerm' => 6, 'maxTerm' => 12], true];
        yield 'set and range' => [TermConstraint::of(terms: [3, 6, 12, 24]), TermConstraint::of(maxTerm: 12), ['terms' => [3, 6, 12]], true];
        yield 'two sets' => [TermConstraint::of(terms: [3, 6, 12]), TermConstraint::of(terms: [6, 12, 24]), ['terms' => [6, 12]], true];
        yield 'with unconstrained' => [TermConstraint::of(maxTerm: 12), TermConstraint::unconstrained(), ['maxTerm' => 12], true];
        yield 'disjoint ranges' => [TermConstraint::of(maxTerm: 6), TermConstraint::of(minTerm: 12), ['minTerm' => 12, 'maxTerm' => 6], false];
        yield 'disjoint sets' => [TermConstraint::of(terms: [3]), TermConstraint::of(terms: [6]), ['terms' => []], false];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('intersectProvider')]
    public function testIntersect(TermConstraint $first, TermConstraint $second, array $expected, bool $satisfiable): void
    {
        $this->assertSame($expected, $first->intersect($second)->getAsArray());
        $this->assertSame($expected, $second->intersect($first)->getAsArray());
        $this->assertSame($satisfiable, $first->intersect($second)->isSatisfiable());
    }

    /**
     * @return iterable<string, array{TermConstraint, TermConstraint, array<string, mixed>}>
     */
    public static function unionProvider(): iterable
    {
        yield 'with unconstrained' => [TermConstraint::of(maxTerm: 12), TermConstraint::unconstrained(), []];
        yield 'nested ranges' => [TermConstraint::of(maxTerm: 12), TermConstraint::of(maxTerm: 24), ['maxTerm' => 24]];
        yield 'overlapping ranges' => [TermConstraint::of(3, 12), TermConstraint::of(10, 24), ['minTerm' => 3, 'maxTerm' => 24]];
        yield 'adjacent ranges' => [TermConstraint::of(1, 6), TermConstraint::of(7, 12), ['minTerm' => 1, 'maxTerm' => 12]];
        yield 'open-ended overlap' => [TermConstraint::of(minTerm: 6), TermConstraint::of(3, 10), ['minTerm' => 3]];
        yield 'disjoint bounded ranges' => [TermConstraint::of(1, 3), TermConstraint::of(10, 12), ['terms' => [1, 2, 3, 10, 11, 12]]];
        yield 'two sets' => [TermConstraint::of(terms: [3, 6]), TermConstraint::of(terms: [6, 12]), ['terms' => [3, 6, 12]]];
        yield 'contiguous set and range' => [TermConstraint::of(terms: [4, 5]), TermConstraint::of(maxTerm: 3), ['maxTerm' => 5]];
        yield 'set inside open range' => [TermConstraint::of(terms: [12, 24]), TermConstraint::of(minTerm: 10), ['minTerm' => 10]];
        yield 'unsatisfiable side' => [TermConstraint::of(12, 6), TermConstraint::of(terms: [3]), ['terms' => [3]]];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('unionProvider')]
    public function testUnion(TermConstraint $first, TermConstraint $second, array $expected): void
    {
        $this->assertSame($expected, $first->union($second)->getAsArray());
        $this->assertSame($expected, $second->union($first)->getAsArray());
    }

    public function testUnionEnumeratesOpenRangeUpToCeilingWhenNotContiguous(): void
    {
        $union = TermConstraint::of(terms: [3])->union(TermConstraint::of(minTerm: 10), 12);

        $this->assertSame([3, 10, 11, 12], $union->terms);
    }

    public function testUnionRaisesCeilingToLargestInvolvedTerm(): void
    {
        $union = TermConstraint::of(terms: [3])->union(TermConstraint::of(minTerm: 10), 8);

        $this->assertSame([3, 10], $union->terms);
    }

    public function testAllowedProductConfigRoundTrip(): void
    {
        $constraint = TermConstraint::fromAllowedProductConfig(new AllowedProductConfig(LoanType::PAY_LATER, 6, 2));
        $dto = $constraint->toAllowedProductConfig(LoanType::PAY_LATER);

        $this->assertSame(LoanType::PAY_LATER, $dto->type);
        $this->assertSame(2, $dto->minTerm);
        $this->assertSame(6, $dto->maxTerm);
        $this->assertNull($dto->terms);
    }
}
