<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Frontend
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Frontend;

use Comfino\Frontend\WidgetInitScriptHelper;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WidgetInitScriptHelperTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testRenderWidgetInitScriptWithValidParams(): void
    {
        $widgetInitCode = WidgetInitScriptHelper::getInitialWidgetCode();
        $widgetInitParams = [
            'WIDGET_KEY' => 'test-widget-key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#widget-target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.price-observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 2,
            'WIDGET_TYPE' => 'simple',
            'OFFER_TYPES' => ['INSTALLMENTS_ZERO_PERCENT', 'PAY_LATER'],
            'EMBED_METHOD' => 'INSERT_INTO_FIRST',
            'SHOW_PROVIDER_LOGOS' => true,
            'CUSTOM_BANNER_CSS_URL' => 'https://example.com/banner.css',
            'CUSTOM_CALCULATOR_CSS_URL' => 'https://example.com/calculator.css',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 123,
            'PRODUCT_PRICE' => 999.99,
            'PLATFORM' => 'WooCommerce',
            'PLATFORM_VERSION' => '8.0.0',
            'PLATFORM_DOMAIN' => 'example-shop.test',
            'PLUGIN_VERSION' => '1.0.0',
            'AVAILABLE_PRODUCT_TYPES' => ['SIMPLE', 'VARIABLE'],
            'PRODUCT_CART_DETAILS' => ['cart_id' => 456],
            'LANGUAGE' => 'pl',
            'CURRENCY' => 'PLN',
        ];

        $widgetInitScript = WidgetInitScriptHelper::renderWidgetInitScript(
            $widgetInitCode,
            $widgetInitParams,
            $widgetInitVariables
        );

        $this->assertStringContainsString('test-widget-key', $widgetInitScript);
        $this->assertStringContainsString('.price', $widgetInitScript);
        $this->assertStringContainsString('#widget-target', $widgetInitScript);
        $this->assertStringContainsString('WooCommerce', $widgetInitScript);
        $this->assertStringContainsString('https://example.com/widget.js', $widgetInitScript);

        // Verify placeholders are replaced.
        $this->assertStringNotContainsString('{WIDGET_KEY}', $widgetInitScript);
        $this->assertStringNotContainsString('{PRODUCT_ID}', $widgetInitScript);
    }

    /**
     * @throws JsonException
     */
    public function testRenderWidgetInitScriptThrowsExceptionWithMissingParams(): void
    {
        $widgetInitCode = WidgetInitScriptHelper::getInitialWidgetCode();
        $incompleteParams = [
            'WIDGET_KEY' => 'test-key',
            // Missing other required params.
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 123,
            'PRODUCT_PRICE' => 999.99,
            'PLATFORM' => 'WooCommerce',
            'PLATFORM_VERSION' => '8.0.0',
            'PLATFORM_DOMAIN' => 'example-shop.test',
            'PLUGIN_VERSION' => '1.0.0',
            'AVAILABLE_PRODUCT_TYPES' => ['SIMPLE'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'pl',
            'CURRENCY' => 'PLN',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid widget initialization parameters');

        WidgetInitScriptHelper::renderWidgetInitScript($widgetInitCode, $incompleteParams, $widgetInitVariables);
    }

    /**
     * @throws JsonException
     */
    public function testRenderWidgetInitScriptThrowsExceptionWithMissingVariables(): void
    {
        $widgetInitCode = WidgetInitScriptHelper::getInitialWidgetCode();
        $widgetInitParams = [
            'WIDGET_KEY' => 'test-widget-key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#widget-target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.price-observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 2,
            'WIDGET_TYPE' => 'simple',
            'OFFER_TYPES' => [],
            'EMBED_METHOD' => 'INSERT_INTO_FIRST',
            'SHOW_PROVIDER_LOGOS' => false,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $incompleteVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            // Missing other required variables.
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid widget initialization variables');

        WidgetInitScriptHelper::renderWidgetInitScript($widgetInitCode, $widgetInitParams, $incompleteVariables);
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('booleanConversionDataProvider')]
    public function testRenderWidgetInitScriptConvertsBooleans(bool $inputValue, string $expectedOutput): void
    {
        $widgetInitCode = 'showProviderLogos: {SHOW_PROVIDER_LOGOS}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'simple',
            'OFFER_TYPES' => [],
            'EMBED_METHOD' => 'INSERT',
            'SHOW_PROVIDER_LOGOS' => $inputValue,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 123,
            'PRODUCT_PRICE' => 999,
            'PLATFORM' => 'Platform',
            'PLATFORM_VERSION' => '1.0.0',
            'PLATFORM_DOMAIN' => 'example.com',
            'PLUGIN_VERSION' => '1.0.0',
            'AVAILABLE_PRODUCT_TYPES' => [],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
        ];

        $this->assertStringContainsString(
            "showProviderLogos: $expectedOutput",
            WidgetInitScriptHelper::renderWidgetInitScript($widgetInitCode, $widgetInitParams, $widgetInitVariables)
        );
    }

    /**
     * @param array<mixed> $inputArray
     *
     * @throws JsonException
     */
    #[DataProvider('arrayConversionDataProvider')]
    public function testRenderWidgetInitScriptConvertsArrays(array $inputArray, string $expectedJson): void
    {
        $widgetInitCode = 'offerTypes: {OFFER_TYPES}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'simple',
            'OFFER_TYPES' => $inputArray,
            'EMBED_METHOD' => 'INSERT',
            'SHOW_PROVIDER_LOGOS' => false,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 123,
            'PRODUCT_PRICE' => 999,
            'PLATFORM' => 'Platform',
            'PLATFORM_VERSION' => '1.0.0',
            'PLATFORM_DOMAIN' => 'example.com',
            'PLUGIN_VERSION' => '1.0.0',
            'AVAILABLE_PRODUCT_TYPES' => [],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
        ];

        $this->assertStringContainsString(
            "offerTypes: $expectedJson",
            WidgetInitScriptHelper::renderWidgetInitScript($widgetInitCode, $widgetInitParams, $widgetInitVariables)
        );
    }

    /**
     * @throws JsonException
     */
    public function testRenderWidgetInitScriptConvertsNullParamToNull(): void
    {
        $widgetInitCode = 'customBannerCss: {CUSTOM_BANNER_CSS_URL}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'simple',
            'OFFER_TYPES' => [],
            'EMBED_METHOD' => 'INSERT',
            'SHOW_PROVIDER_LOGOS' => false,
            'CUSTOM_BANNER_CSS_URL' => null,
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 1,
            'PRODUCT_PRICE' => 100,
            'PLATFORM' => 'Platform',
            'PLATFORM_VERSION' => '1.0.0',
            'PLATFORM_DOMAIN' => 'example.com',
            'PLUGIN_VERSION' => '1.0.0',
            'AVAILABLE_PRODUCT_TYPES' => [],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
        ];

        $this->assertStringContainsString(
            'customBannerCss: null',
            WidgetInitScriptHelper::renderWidgetInitScript($widgetInitCode, $widgetInitParams, $widgetInitVariables)
        );
    }

    /**
     * @throws JsonException
     */
    public function testRenderWidgetInitScriptConvertsNullToNull(): void
    {
        $widgetInitCode = 'productId: {PRODUCT_ID}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'simple',
            'OFFER_TYPES' => [],
            'EMBED_METHOD' => 'INSERT',
            'SHOW_PROVIDER_LOGOS' => false,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => null,
            'PRODUCT_PRICE' => 999,
            'PLATFORM' => 'Platform',
            'PLATFORM_VERSION' => '1.0.0',
            'PLATFORM_DOMAIN' => 'example.com',
            'PLUGIN_VERSION' => '1.0.0',
            'AVAILABLE_PRODUCT_TYPES' => [],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
        ];

        $this->assertStringContainsString(
            'productId: null',
            WidgetInitScriptHelper::renderWidgetInitScript($widgetInitCode, $widgetInitParams, $widgetInitVariables)
        );
    }

    public function testInitScriptRequiresUpdateReturnsTrueForDifferentCode(): void
    {
        $modifiedCode = 'const script = document.createElement("div");'; // Different from initial code.

        $this->assertTrue(WidgetInitScriptHelper::initScriptRequiresUpdate($modifiedCode));
    }

    public function testInitScriptRequiresUpdateReturnsFalseForSameCode(): void
    {
        $this->assertFalse(
            WidgetInitScriptHelper::initScriptRequiresUpdate(WidgetInitScriptHelper::getInitialWidgetCode())
        );
    }

    public function testGetInitialWidgetCodeHashReturnsConsistentHash(): void
    {
        $hash1 = WidgetInitScriptHelper::getInitialWidgetCodeHash();
        $hash2 = WidgetInitScriptHelper::getInitialWidgetCodeHash();

        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(64, strlen($hash1)); // SHA-256 hash is 64 characters.
    }

    public function testGetInitialWidgetCodeReturnsString(): void
    {
        $code = WidgetInitScriptHelper::getInitialWidgetCode();

        $this->assertNotEmpty($code);
        $this->assertStringContainsString('ComfinoWidgetFrontend.init', $code);
        $this->assertStringContainsString('const script = document.createElement', $code);
    }

    public function testGetInitialWidgetCodeContainsAllPlaceholders(): void
    {
        $code = WidgetInitScriptHelper::getInitialWidgetCode();

        // Check for parameter placeholders.
        foreach (WidgetInitScriptHelper::WIDGET_INIT_PARAMS as $param) {
            $this->assertStringContainsString('{' . $param . '}', $code);
        }

        // Check for variable placeholders.
        foreach (WidgetInitScriptHelper::WIDGET_INIT_VARIABLES as $variable) {
            $this->assertStringContainsString('{' . $variable . '}', $code);
        }
    }

    /**
     * @throws JsonException
     */
    #[DataProvider('xssInjectionDataProvider')]
    public function testRenderWidgetInitScriptEscapesSpecialCharactersInStrings(
        string $maliciousInput,
        string $forbiddenOutput
    ): void {
        $widgetInitCode = "widgetKey: {WIDGET_KEY}";
        $widgetInitParams = [
            'WIDGET_KEY' => $maliciousInput,
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'simple',
            'OFFER_TYPES' => [],
            'EMBED_METHOD' => 'INSERT',
            'SHOW_PROVIDER_LOGOS' => false,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 1,
            'PRODUCT_PRICE' => 100,
            'PLATFORM' => 'Platform',
            'PLATFORM_VERSION' => '1.0.0',
            'PLATFORM_DOMAIN' => 'example.com',
            'PLUGIN_VERSION' => '1.0.0',
            'AVAILABLE_PRODUCT_TYPES' => [],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
        ];

        $this->assertStringNotContainsString(
            $forbiddenOutput,
            WidgetInitScriptHelper::renderWidgetInitScript($widgetInitCode, $widgetInitParams, $widgetInitVariables)
        );
    }

    /**
     * @throws JsonException
     */
    public function testRenderWidgetInitScriptRendersNumericValuesWithoutQuotes(): void
    {
        $widgetInitCode = 'productId: {PRODUCT_ID}, productPrice: {PRODUCT_PRICE}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'simple',
            'OFFER_TYPES' => [],
            'EMBED_METHOD' => 'INSERT',
            'SHOW_PROVIDER_LOGOS' => false,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 42,
            'PRODUCT_PRICE' => 1999,
            'PLATFORM' => 'Platform',
            'PLATFORM_VERSION' => '1.0.0',
            'PLATFORM_DOMAIN' => 'example.com',
            'PLUGIN_VERSION' => '1.0.0',
            'AVAILABLE_PRODUCT_TYPES' => [],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
        ];

        $widgetInitScript = WidgetInitScriptHelper::renderWidgetInitScript(
            $widgetInitCode,
            $widgetInitParams,
            $widgetInitVariables
        );

        $this->assertStringContainsString('productId: 42', $widgetInitScript);
        $this->assertStringContainsString('productPrice: 1999', $widgetInitScript);
        // Numeric values must not be wrapped in quotes.
        $this->assertStringNotContainsString('productId: "42"', $widgetInitScript);
        $this->assertStringNotContainsString("productId: '42'", $widgetInitScript);
    }

    /* Data providers */

    /** @return array<string, array{string, string}> */
    public static function xssInjectionDataProvider(): array
    {
        return [
            'Single quote terminates JS string' => ["'; alert(1); var x='", "'"],
            'Closing script tag' => ['</script><script>alert(1)</script>', '</script>'],
            'Double quote breaks attribute' => ['" onload="alert(1)', '" onload="'],
            'Ampersand entity' => ['key&value', '&'],
        ];
    }

    /** @return array<string, array{bool, string}> */
    public static function booleanConversionDataProvider(): array
    {
        return [
            'True converts to true' => [true, 'true'],
            'False converts to false' => [false, 'false'],
        ];
    }

    /** @return array<string, array{array<mixed>, string}> */
    public static function arrayConversionDataProvider(): array
    {
        return [
            'Empty array' => [[], '[]'],
            'Array with strings' => [['A', 'B', 'C'], '["A","B","C"]'],
            'Array with mixed types' => [[1, 'test', true], '[1,"test",true]'],
            'Nested array' => [['key' => ['nested' => 'value']], '{"key":{"nested":"value"}}'],
        ];
    }
}
