<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Payment;

use Comfino\Api\Dto\Payment\AllowedProductConfig;
use Comfino\Backend\Payment\AllowedProductsConfigBuilder;
use Comfino\Enum\LoanType;
use PHPUnit\Framework\TestCase;

final class AllowedProductsConfigBuilderTest extends TestCase
{
    public function testFromPersistedArrayReturnsNullForNull(): void
    {
        $this->assertNull(AllowedProductsConfigBuilder::fromPersistedArray(null));
    }

    public function testFromPersistedArrayReturnsNullForEmptyArray(): void
    {
        $this->assertNull(AllowedProductsConfigBuilder::fromPersistedArray([]));
    }

    public function testFromPersistedArrayReturnsNullForNonArray(): void
    {
        $this->assertNull(AllowedProductsConfigBuilder::fromPersistedArray('invalid'));
        $this->assertNull(AllowedProductsConfigBuilder::fromPersistedArray(42));
    }

    public function testFromPersistedArraySkipsEntriesWithoutType(): void
    {
        $this->assertNull(AllowedProductsConfigBuilder::fromPersistedArray([
            ['maxTerm' => 6],
            ['minTerm' => 1],
        ]));
    }

    public function testFromPersistedArraySkipsEntriesWithEmptyType(): void
    {
        $this->assertNull(AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => '', 'maxTerm' => 6],
        ]));
    }

    public function testFromPersistedArrayBuildsFullEntry(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'maxTerm' => 12, 'minTerm' => 1, 'terms' => [3, 6, 12]],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(AllowedProductConfig::class, $result[0]);
        $this->assertSame(LoanType::PAY_LATER, $result[0]->type);
        $this->assertSame(12, $result[0]->maxTerm);
        $this->assertSame(1, $result[0]->minTerm);
        $this->assertSame([3, 6, 12], $result[0]->terms);
    }

    public function testFromPersistedArrayBuildsTermsOnlyEntry(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'INSTALLMENTS_ZERO_PERCENT', 'terms' => ['3', '6']],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertNull($result[0]->maxTerm);
        $this->assertNull($result[0]->minTerm);
        $this->assertSame([3, 6], $result[0]->terms);
    }

    public function testFromPersistedArrayCoercesNumericTermValues(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'maxTerm' => '24', 'minTerm' => '3'],
        ]);

        $this->assertNotNull($result);
        $this->assertSame(24, $result[0]->maxTerm);
        $this->assertSame(3, $result[0]->minTerm);
    }

    public function testFromPersistedArrayBuildsMultipleEntries(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'maxTerm' => 3],
            ['type' => 'INSTALLMENTS_ZERO_PERCENT', 'minTerm' => 6],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
    }

    public function testFromPersistedArraySkipsMalformedEntries(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'maxTerm' => 3],
            ['maxTerm' => 6],
            'not-an-array',
        ]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
    }

    public function testToFrontendArrayReturnsNullForNull(): void
    {
        $this->assertNull(AllowedProductsConfigBuilder::toFrontendArray(null));
    }

    public function testToFrontendArrayReturnsNullForEmptyArray(): void
    {
        $this->assertNull(AllowedProductsConfigBuilder::toFrontendArray([]));
    }

    public function testToFrontendArrayConvertsFullDto(): void
    {
        $dtos = [
            new AllowedProductConfig(LoanType::PAY_LATER, 12, 1, [3, 6, 12]),
        ];

        $result = AllowedProductsConfigBuilder::toFrontendArray($dtos);

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame('PAY_LATER', $result[0]['type']);
        $this->assertSame(12, $result[0]['maxTerm']);
        $this->assertSame(1, $result[0]['minTerm']);
        $this->assertSame([3, 6, 12], $result[0]['terms']);
    }

    public function testToFrontendArrayOmitsNullFields(): void
    {
        $dtos = [
            new AllowedProductConfig(LoanType::INSTALLMENTS_ZERO_PERCENT),
        ];

        $result = AllowedProductsConfigBuilder::toFrontendArray($dtos);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('type', $result[0]);
        $this->assertArrayNotHasKey('maxTerm', $result[0]);
        $this->assertArrayNotHasKey('minTerm', $result[0]);
        $this->assertArrayNotHasKey('terms', $result[0]);
    }

    public function testFromPersistedArraySkipsUnknownProductType(): void
    {
        $this->assertNull(AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'NOT_A_REAL_PRODUCT', 'maxTerm' => 6],
        ]));
    }

    public function testFromPersistedArrayMixesKnownAndUnknownTypes(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'maxTerm' => 3],
            ['type' => 'NOT_A_REAL_PRODUCT', 'maxTerm' => 6],
            ['type' => 'INSTALLMENTS_ZERO_PERCENT', 'minTerm' => 6, 'maxTerm' => 24],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertSame(LoanType::PAY_LATER, $result[0]->type);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $result[1]->type);
    }

    public function testFromPersistedArrayFiltersNonPositiveTerms(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'terms' => [0, -3, 6, 12, '0', 'abc']],
        ]);

        $this->assertNotNull($result);
        $this->assertSame([6, 12], $result[0]->terms);
    }

    public function testFromPersistedArrayDropsTermsFieldWhenAllInvalid(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'maxTerm' => 6, 'terms' => [0, -1, -5]],
        ]);

        $this->assertNotNull($result);
        $this->assertNull($result[0]->terms);
        $this->assertSame(6, $result[0]->maxTerm);
    }

    public function testFromPersistedArrayDropsEntryWithMinTermGreaterThanMaxTerm(): void
    {
        $this->assertNull(AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'minTerm' => 12, 'maxTerm' => 6],
        ]));
    }

    public function testFromPersistedArrayKeepsEntryWithMinTermEqualToMaxTerm(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'minTerm' => 6, 'maxTerm' => 6],
        ]);

        $this->assertNotNull($result);
        $this->assertSame(6, $result[0]->minTerm);
        $this->assertSame(6, $result[0]->maxTerm);
    }

    public function testFromPersistedArrayIgnoresNonNumericTermBounds(): void
    {
        $result = AllowedProductsConfigBuilder::fromPersistedArray([
            ['type' => 'PAY_LATER', 'minTerm' => 'banana', 'maxTerm' => null, 'terms' => [3, 6]],
        ]);

        $this->assertNotNull($result);
        $this->assertNull($result[0]->minTerm);
        $this->assertNull($result[0]->maxTerm);
        $this->assertSame([3, 6], $result[0]->terms);
    }

    public function testRoundTripFromPersistedToFrontend(): void
    {
        $persisted = [
            ['type' => 'PAY_LATER', 'maxTerm' => 6, 'terms' => [3, 6]],
            ['type' => 'INSTALLMENTS_ZERO_PERCENT', 'minTerm' => 3],
        ];

        $dtos = AllowedProductsConfigBuilder::fromPersistedArray($persisted);
        $frontend = AllowedProductsConfigBuilder::toFrontendArray($dtos);

        $this->assertNotNull($frontend);
        $this->assertCount(2, $frontend);
        $this->assertSame('PAY_LATER', $frontend[0]['type']);
        $this->assertSame(6, $frontend[0]['maxTerm']);
        $this->assertSame([3, 6], $frontend[0]['terms']);
        $this->assertSame('INSTALLMENTS_ZERO_PERCENT', $frontend[1]['type']);
        $this->assertSame(3, $frontend[1]['minTerm']);
    }
}
