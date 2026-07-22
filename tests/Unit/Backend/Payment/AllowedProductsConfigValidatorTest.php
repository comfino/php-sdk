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

use Comfino\Backend\Payment\AllowedProductsConfigBuilder;
use Comfino\Backend\Payment\AllowedProductsConfigValidator;
use PHPUnit\Framework\TestCase;

final class AllowedProductsConfigValidatorTest extends TestCase
{
    private AllowedProductsConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AllowedProductsConfigValidator();
    }

    /**
     * @dataProvider emptyInputProvider
     */
    public function testEmptyInputIsValidAndNormalizesToEmptyString(string $raw): void
    {
        $result = $this->validator->validateAndNormalize($raw);

        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
        self::assertSame('', $result['normalizedJson']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function emptyInputProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace' => ["  \n\t "],
            'empty array' => ['[]'],
        ];
    }

    /**
     * @dataProvider invalidInputProvider
     *
     * @param array<int, mixed> $expectedParams
     */
    public function testInvalidInputProducesExpectedKey(string $raw, string $expectedKey, array $expectedParams): void
    {
        $result = $this->validator->validateAndNormalize($raw);

        self::assertFalse($result['valid']);
        self::assertNull($result['normalizedJson']);
        self::assertCount(1, $result['errors']);
        self::assertSame($expectedKey, $result['errors'][0]['key']);
        self::assertSame($expectedParams, $result['errors'][0]['params']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, mixed>}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'malformed json' => [
                '[{',
                AllowedProductsConfigValidator::ERROR_INVALID_JSON,
                [json_last_error() === JSON_ERROR_NONE ? 'Syntax error' : json_last_error_msg()],
            ],
            'object not list' => ['{"type":"PAY_LATER"}', AllowedProductsConfigValidator::ERROR_NOT_ARRAY, []],
            'scalar' => ['5', AllowedProductsConfigValidator::ERROR_NOT_ARRAY, []],
            'entry not object' => ['[1]', AllowedProductsConfigValidator::ERROR_ENTRY_NOT_OBJECT, [1]],
            'missing type' => ['[{"minTerm":3}]', AllowedProductsConfigValidator::ERROR_MISSING_TYPE, [1]],
            'unknown type' => [
                '[{"type":"NOPE"}]',
                AllowedProductsConfigValidator::ERROR_UNKNOWN_TYPE,
                [1, 'NOPE'],
            ],
            'minTerm not positive' => [
                '[{"type":"PAY_LATER","minTerm":0}]',
                AllowedProductsConfigValidator::ERROR_FIELD_NOT_POSITIVE_INT,
                [1, 'minTerm'],
            ],
            'maxTerm not numeric' => [
                '[{"type":"PAY_LATER","maxTerm":"x"}]',
                AllowedProductsConfigValidator::ERROR_FIELD_NOT_POSITIVE_INT,
                [1, 'maxTerm'],
            ],
            'minTerm greater than maxTerm' => [
                '[{"type":"PAY_LATER","minTerm":6,"maxTerm":3}]',
                AllowedProductsConfigValidator::ERROR_MIN_TERM_GREATER_THAN_MAX_TERM,
                [1, 'PAY_LATER', 6, 3],
            ],
            'terms not array' => [
                '[{"type":"PAY_LATER","terms":"3,6"}]',
                AllowedProductsConfigValidator::ERROR_TERMS_NOT_ARRAY,
                [1, 'PAY_LATER'],
            ],
            'term not positive' => [
                '[{"type":"PAY_LATER","terms":[3,0]}]',
                AllowedProductsConfigValidator::ERROR_TERM_NOT_POSITIVE_INT,
                [1, 'PAY_LATER', 1],
            ],
        ];
    }

    public function testNoOpEntryIsDroppedAndNormalizesToEmptyString(): void
    {
        $result = $this->validator->validateAndNormalize('[{"type":"PAY_LATER"}]');

        self::assertTrue($result['valid']);
        self::assertSame('', $result['normalizedJson']);
    }

    /**
     * @throws \JsonException
     */
    public function testCanonicalNormalizationDedupesTermsAndRoundTripsThroughBuilder(): void
    {
        $result = $this->validator->validateAndNormalize(
            '[{"terms":[6,3,6,3],"maxTerm":6,"minTerm":1,"type":"PAY_LATER"}]'
        );

        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);

        // Canonical: keys ordered type/minTerm/maxTerm/terms, terms deduped preserving first-seen order.
        self::assertSame(
            '[{"type":"PAY_LATER","minTerm":1,"maxTerm":6,"terms":[6,3]}]',
            $result['normalizedJson']
        );

        // The normalized value must be consumable by the SDK builder that reads it back.
        $dtos = AllowedProductsConfigBuilder::fromPersistedArray(
            json_decode($result['normalizedJson'], true, 512, JSON_THROW_ON_ERROR)
        );

        self::assertNotNull($dtos);
        self::assertCount(1, $dtos);
        self::assertSame(
            $result['normalizedJson'],
            (string) json_encode(
                AllowedProductsConfigBuilder::toFrontendArray($dtos),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }
}
