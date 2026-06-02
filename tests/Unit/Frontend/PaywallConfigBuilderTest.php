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

use Comfino\Frontend\PaywallConfig;
use Comfino\Frontend\PaywallConfigBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaywallConfigBuilderTest extends TestCase
{
    /**
     * @param string[]|null $allowedProductTypes
     */
    #[DataProvider('buildConfigDataProvider')]
    public function testBuildConfigCreatesValidPaywallConfig(
        string $apiKey,
        string $widgetKey,
        int $loanAmount,
        bool $sandboxMode,
        string $sdkScriptUrl,
        ?array $allowedProductTypes
    ): void {
        $config = PaywallConfigBuilder::buildConfig(
            $apiKey,
            $widgetKey,
            $loanAmount,
            $sandboxMode,
            $sdkScriptUrl,
            $allowedProductTypes
        );

        $this->assertEquals($loanAmount, $config->loanAmount);
        $this->assertEquals($sdkScriptUrl, $config->sdkScriptUrl);
        $this->assertEquals($allowedProductTypes, $config->allowedProductTypes);
    }

    public function testBuildConfigSetsProductionEnvironment(): void
    {
        $config = PaywallConfigBuilder::buildConfig(
            'api-key',
            'widget-key',
            10000,
            false,
            'https://example.com/sdk.js',
            null
        );

        $this->assertEquals('production', $config->environment);
    }

    public function testBuildConfigSetsSandboxEnvironment(): void
    {
        $config = PaywallConfigBuilder::buildConfig(
            'api-key',
            'widget-key',
            10000,
            true,
            'https://example.com/sdk.js',
            null
        );

        $this->assertEquals('sandbox', $config->environment);
    }

    public function testBuildConfigGeneratesAuthToken(): void
    {
        $config = PaywallConfigBuilder::buildConfig(
            'api-key',
            'widget-key',
            10000,
            false,
            'https://example.com/sdk.js',
            null
        );

        $this->assertNotEmpty($config->authToken);
        $this->assertIsString($config->authToken);
    }

    public function testBuildConfigWithValidKeys(): void
    {
        $config = PaywallConfigBuilder::buildConfig(
            'test-api-key-123',
            '123e4567-e89b-12d3-a456-426614174000',
            50000,
            false,
            'https://sdk.comfino.pl/widget.js',
            ['INSTALLMENTS_ZERO_PERCENT', 'PAY_LATER']
        );

        $this->assertNotEmpty($config->authToken);
        $this->assertEquals(50000, $config->loanAmount);
        $this->assertEquals('production', $config->environment);
        $this->assertEqualsCanonicalizing(['INSTALLMENTS_ZERO_PERCENT', 'PAY_LATER'], $config->allowedProductTypes);
    }

    public function testBuildConfigWithNullProductTypes(): void
    {
        $config = PaywallConfigBuilder::buildConfig(
            'api-key',
            'widget-key',
            10000,
            false,
            'https://example.com/sdk.js',
            null
        );

        $this->assertNull($config->allowedProductTypes);
    }

    public function testBuildConfigWithEmptyProductTypesArray(): void
    {
        $config = PaywallConfigBuilder::buildConfig(
            'api-key',
            'widget-key',
            10000,
            false,
            'https://example.com/sdk.js',
            []
        );

        $this->assertEmpty($config->allowedProductTypes);
    }

    public function testBuildConfigWithSingleProductType(): void
    {
        $productTypes = ['INSTALLMENTS_ZERO_PERCENT'];

        $config = PaywallConfigBuilder::buildConfig(
            'api-key',
            'widget-key',
            10000,
            false,
            'https://example.com/sdk.js',
            $productTypes
        );

        $this->assertEqualsCanonicalizing($productTypes, $config->allowedProductTypes);
    }

    public function testBuildConfigWithMultipleProductTypes(): void
    {
        $productTypes = ['INSTALLMENTS_ZERO_PERCENT', 'PAY_LATER', 'CONVENIENT_INSTALLMENTS', 'LEASING'];

        $config = PaywallConfigBuilder::buildConfig(
            'api-key',
            'widget-key',
            10000,
            false,
            'https://example.com/sdk.js',
            $productTypes
        );

        $this->assertEqualsCanonicalizing($productTypes, $config->allowedProductTypes);
    }

    public function testBuildConfigReturnsPaywallConfig(): void
    {
        $config = PaywallConfigBuilder::buildConfig(
            'api-key',
            'widget-key',
            10000,
            false,
            'https://example.com/sdk.js',
            null
        );

        $this->assertInstanceOf(PaywallConfig::class, $config);
    }

    public function testBuildConfigConfigCanBeConvertedToArray(): void
    {
        $config = PaywallConfigBuilder::buildConfig(
            'api-key',
            'widget-key',
            10000,
            false,
            'https://example.com/sdk.js',
            ['INSTALLMENTS_ZERO_PERCENT']
        );

        $array = $config->getAsArray();

        $this->assertArrayHasKey('authToken', $array);
        $this->assertArrayHasKey('loanAmount', $array);
        $this->assertArrayHasKey('environment', $array);
        $this->assertArrayHasKey('sdkScriptUrl', $array);
        $this->assertArrayHasKey('allowedProductTypes', $array);
        $this->assertEquals(10000, $array['loanAmount']);
        $this->assertEquals('production', $array['environment']);
    }

    /**
     * @param string[]|null $allowedProductTypes
     */
    #[DataProvider('shouldShowPaywallDataProvider')]
    public function testShouldShowPaywall(int $loanAmount, ?array $allowedProductTypes, bool $expected): void
    {
        $result = PaywallConfigBuilder::shouldShowPaywall($loanAmount, $allowedProductTypes);

        $this->assertEquals($expected, $result);
    }

    public function testShouldShowPaywallReturnsTrueForValidAmount(): void
    {
        $this->assertTrue(PaywallConfigBuilder::shouldShowPaywall(10000, null));
    }

    public function testShouldShowPaywallReturnsTrueForPositiveAmount(): void
    {
        $this->assertTrue(PaywallConfigBuilder::shouldShowPaywall(1, null));
    }

    public function testShouldShowPaywallReturnsFalseForZeroAmount(): void
    {
        $this->assertFalse(PaywallConfigBuilder::shouldShowPaywall(0, null));
    }

    public function testShouldShowPaywallReturnsFalseForNegativeAmount(): void
    {
        $this->assertFalse(PaywallConfigBuilder::shouldShowPaywall(-100, null));
    }

    public function testShouldShowPaywallReturnsFalseForEmptyProductTypesArray(): void
    {
        $this->assertFalse(PaywallConfigBuilder::shouldShowPaywall(10000, []));
    }

    public function testShouldShowPaywallReturnsTrueForNullProductTypes(): void
    {
        $this->assertTrue(PaywallConfigBuilder::shouldShowPaywall(10000, null));
    }

    public function testShouldShowPaywallReturnsTrueForNonEmptyProductTypes(): void
    {
        $this->assertTrue(PaywallConfigBuilder::shouldShowPaywall(10000, ['INSTALLMENT_0M']));
    }

    public function testShouldShowPaywallReturnsTrueForMultipleProductTypes(): void
    {
        $this->assertTrue(PaywallConfigBuilder::shouldShowPaywall(
            10000,
            ['INSTALLMENTS_ZERO_PERCENT', 'PAY_LATER', 'CONVENIENT_INSTALLMENTS']
        ));
    }

    public function testShouldShowPaywallEdgeCaseZeroAmountEmptyTypes(): void
    {
        // Both conditions for hiding should return false.
        $this->assertFalse(PaywallConfigBuilder::shouldShowPaywall(0, []));
    }

    public function testShouldShowPaywallEdgeCaseNegativeAmountEmptyTypes(): void
    {
        // Both conditions for hiding should return false.
        $this->assertFalse(PaywallConfigBuilder::shouldShowPaywall(-50, []));
    }

    public function testShouldShowPaywallWithLargeAmount(): void
    {
        $this->assertTrue(PaywallConfigBuilder::shouldShowPaywall(999999999, null));
    }

    /* Data providers */

    /** @return array<string, array{string, string, int, bool, string, string[]|null}> */
    public static function buildConfigDataProvider(): array
    {
        return [
            'Basic config' => [
                'test-api-key',
                'test-widget-key',
                10000,
                false,
                'https://example.com/sdk.js',
                null
            ],
            'Sandbox mode' => [
                'sandbox-api-key',
                'sandbox-widget-key',
                5000,
                true,
                'https://example.com/sdk.js',
                null
            ],
            'With product types' => [
                'api-key',
                'widget-key',
                20000,
                false,
                'https://example.com/sdk.js',
                ['INSTALLMENTS_ZERO_PERCENT', 'PAY_LATER']
            ],
            'Large loan amount' => [
                'api-key',
                'widget-key',
                999999,
                false,
                'https://example.com/sdk.js',
                null
            ],
            'Small loan amount' => [
                'api-key',
                'widget-key',
                1,
                false,
                'https://example.com/sdk.js',
                null
            ],
        ];
    }

    /** @return array<string, array{int, string[]|null, bool}> */
    public static function shouldShowPaywallDataProvider(): array
    {
        return [
            'Valid amount and no type filter' => [10000, null, true],
            'Valid amount and single type' => [10000, ['INSTALLMENTS_ZERO_PERCENT'], true],
            'Valid amount and multiple types' => [10000, ['INSTALLMENTS_ZERO_PERCENT', 'PAY_LATER'], true],
            'Zero amount' => [0, null, false],
            'Negative amount' => [-100, null, false],
            'Empty product types' => [10000, [], false],
            'Very small positive amount' => [1, null, true],
            'Large amount' => [999999999, null, true],
            'Negative amount with empty types' => [-50, [], false],
            'Zero amount with types' => [0, ['INSTALLMENTS_ZERO_PERCENT'], false],
            'Negative amount with types' => [-100, ['INSTALLMENTS_ZERO_PERCENT'], false],
        ];
    }
}
