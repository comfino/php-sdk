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

use Comfino\Frontend\WidgetFrontendInitScriptHelper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WidgetFrontendInitScriptHelperTest extends TestCase
{
    public function testRenderWidgetInitScriptWithValidParams(): void
    {
        $widgetInitCode = WidgetFrontendInitScriptHelper::getInitialWidgetCode();
        $widgetInitParams = [
            'WIDGET_KEY' => 'test-widget-key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#widget-target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.price-observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 2,
            'WIDGET_TYPE' => 'standard',
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
            'AVAILABLE_PRODUCT_TYPES' => ['INSTALLMENTS_ZERO_PERCENT', 'PAY_LATER'],
            'PRODUCT_CART_DETAILS' => ['cart_id' => 456],
            'LANGUAGE' => 'pl',
            'CURRENCY' => 'PLN',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $widgetInitScript = WidgetFrontendInitScriptHelper::renderWidgetInitScript(
            $widgetInitCode,
            $widgetInitParams,
            $widgetInitVariables
        );

        $this->assertStringContainsString('test-widget-key', $widgetInitScript);
        $this->assertStringContainsString('.price', $widgetInitScript);
        $this->assertStringContainsString('#widget-target', $widgetInitScript);
        $this->assertStringContainsString('production', $widgetInitScript);
        $this->assertStringContainsString('https://example.com/widget.js', $widgetInitScript);

        // Verify placeholders are replaced.
        $this->assertStringNotContainsString('{WIDGET_KEY}', $widgetInitScript);
        $this->assertStringNotContainsString('{PRODUCT_ID}', $widgetInitScript);
    }

    public function testRenderWidgetInitScriptThrowsExceptionWithMissingParams(): void
    {
        $widgetInitCode = WidgetFrontendInitScriptHelper::getInitialWidgetCode();
        $incompleteParams = [
            'WIDGET_KEY' => 'test-key',
            // Missing other required params.
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 123,
            'PRODUCT_PRICE' => 999.99,
            'AVAILABLE_PRODUCT_TYPES' => ['PAY_LATER'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'pl',
            'CURRENCY' => 'PLN',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid widget initialization parameters');

        WidgetFrontendInitScriptHelper::renderWidgetInitScript(
            $widgetInitCode,
            $incompleteParams,
            $widgetInitVariables
        );
    }

    public function testRenderWidgetInitScriptThrowsExceptionWithMissingVariables(): void
    {
        $widgetInitCode = WidgetFrontendInitScriptHelper::getInitialWidgetCode();
        $widgetInitParams = [
            'WIDGET_KEY' => 'test-widget-key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#widget-target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.price-observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 2,
            'WIDGET_TYPE' => 'standard',
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

        WidgetFrontendInitScriptHelper::renderWidgetInitScript(
            $widgetInitCode,
            $widgetInitParams,
            $incompleteVariables
        );
    }

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
            'WIDGET_TYPE' => 'standard',
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
            'AVAILABLE_PRODUCT_TYPES' => ['CONVENIENT_INSTALLMENTS', 'LEASING'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $this->assertStringContainsString(
            "showProviderLogos: $expectedOutput",
            WidgetFrontendInitScriptHelper::renderWidgetInitScript(
                $widgetInitCode,
                $widgetInitParams,
                $widgetInitVariables
            )
        );
    }

    /**
     * @param mixed[] $inputArray
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
            'WIDGET_TYPE' => 'standard',
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
            'AVAILABLE_PRODUCT_TYPES' => ['CONVENIENT_INSTALLMENTS', 'LEASING'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $this->assertStringContainsString(
            "offerTypes: $expectedJson",
            WidgetFrontendInitScriptHelper::renderWidgetInitScript(
                $widgetInitCode,
                $widgetInitParams,
                $widgetInitVariables
            )
        );
    }

    /**
     * Regression: callers must pass array params (notably OFFER_TYPES) as native PHP arrays.
     *
     * Pre-serializing the array to a JSON string before calling renderWidgetInitScript causes the helper's *string*
     * branch to wrap it in JSON quotes again with JSON_HEX_QUOT, producing `offerTypes: "["CONVENIENT_INSTALLMENTS",
     * "LEASING"]"`. The widget frontend then sees a string instead of an array and logs `offerTypes option not set or
     * empty`. This test asserts the correct behavior: arrays render as JS array literals, and the resulting JS contains
     * no `"` artifacts.
     */
    public function testRenderWidgetInitScriptOfferTypesArrayMustNotBePreSerialized(): void
    {
        $widgetInitCode = 'offerTypes: {OFFER_TYPES}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'standard',
            'OFFER_TYPES' => ['CONVENIENT_INSTALLMENTS', 'LEASING'],
            'EMBED_METHOD' => 'INSERT_INTO_LAST',
            'SHOW_PROVIDER_LOGOS' => false,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 1,
            'PRODUCT_PRICE' => 100,
            'AVAILABLE_PRODUCT_TYPES' => ['CONVENIENT_INSTALLMENTS', 'LEASING'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'PLN',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $rendered = WidgetFrontendInitScriptHelper::renderWidgetInitScript(
            $widgetInitCode,
            $widgetInitParams,
            $widgetInitVariables
        );

        $this->assertStringContainsString('offerTypes: ["CONVENIENT_INSTALLMENTS","LEASING"]', $rendered);
        $this->assertStringNotContainsString('\\u0022', $rendered);
        $this->assertStringNotContainsString('offerTypes: "', $rendered);
    }

    /**
     * Demonstrates the failure mode when a caller pre-encodes the array. This guards against regressions in shop-side
     * controllers (e.g., Magento Controller/Script/Index) that wrap config values through their own JsonSerializer
     * before handing them to the helper. The helper has no way to detect this mistake, so the test pins down the
     * visible artifact.
     */
    public function testRenderWidgetInitScriptPreSerializedOfferTypesProducesEscapedQuotes(): void
    {
        $widgetInitCode = 'offerTypes: {OFFER_TYPES}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'standard',
            'OFFER_TYPES' => '["CONVENIENT_INSTALLMENTS","LEASING"]', // wrong: pre-encoded
            'EMBED_METHOD' => 'INSERT_INTO_LAST',
            'SHOW_PROVIDER_LOGOS' => false,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 1,
            'PRODUCT_PRICE' => 100,
            'AVAILABLE_PRODUCT_TYPES' => ['CONVENIENT_INSTALLMENTS', 'LEASING'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'PLN',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $rendered = WidgetFrontendInitScriptHelper::renderWidgetInitScript(
            $widgetInitCode,
            $widgetInitParams,
            $widgetInitVariables
        );

        $this->assertStringContainsString('\\u0022', $rendered);
        $this->assertStringContainsString('offerTypes: "', $rendered);
    }

    public function testRenderWidgetInitScriptConvertsNullParamToNull(): void
    {
        $widgetInitCode = 'customBannerCss: {CUSTOM_BANNER_CSS_URL}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'standard',
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
            'AVAILABLE_PRODUCT_TYPES' => ['CONVENIENT_INSTALLMENTS', 'LEASING'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $this->assertStringContainsString(
            'customBannerCss: null',
            WidgetFrontendInitScriptHelper::renderWidgetInitScript(
                $widgetInitCode,
                $widgetInitParams,
                $widgetInitVariables
            )
        );
    }

    public function testRenderWidgetInitScriptConvertsNullToNull(): void
    {
        $widgetInitCode = 'productId: {PRODUCT_ID}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'standard',
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
            'AVAILABLE_PRODUCT_TYPES' => ['CONVENIENT_INSTALLMENTS', 'LEASING'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $this->assertStringContainsString(
            'productId: null',
            WidgetFrontendInitScriptHelper::renderWidgetInitScript(
                $widgetInitCode,
                $widgetInitParams,
                $widgetInitVariables
            )
        );
    }

    public function testInitScriptRequiresUpdateReturnsTrueForDifferentCode(): void
    {
        $modifiedCode = 'const script = document.createElement("div");'; // Different from initial code.

        $this->assertTrue(WidgetFrontendInitScriptHelper::initScriptRequiresUpdate($modifiedCode));
    }

    public function testInitScriptRequiresUpdateReturnsFalseForSameCode(): void
    {
        $this->assertFalse(
            WidgetFrontendInitScriptHelper::initScriptRequiresUpdate(
                WidgetFrontendInitScriptHelper::getInitialWidgetCode()
            )
        );
    }

    public function testGetInitialWidgetCodeHashReturnsConsistentHash(): void
    {
        $hash1 = WidgetFrontendInitScriptHelper::getInitialWidgetCodeHash();
        $hash2 = WidgetFrontendInitScriptHelper::getInitialWidgetCodeHash();

        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(64, strlen($hash1)); // SHA-256 hash is 64 characters.
    }

    public function testGetInitialWidgetCodeReturnsString(): void
    {
        $code = WidgetFrontendInitScriptHelper::getInitialWidgetCode();

        $this->assertNotEmpty($code);
        $this->assertStringContainsString('ComfinoWidgetFrontend.init', $code);
        $this->assertStringContainsString('const script = document.createElement', $code);
    }

    public function testGetInitialWidgetCodeContainsAllPlaceholders(): void
    {
        $code = WidgetFrontendInitScriptHelper::getInitialWidgetCode();

        // Check for parameter placeholders.
        foreach (WidgetFrontendInitScriptHelper::WIDGET_INIT_PARAMS as $param) {
            $this->assertStringContainsString('{' . $param . '}', $code);
        }

        // Check for variable placeholders.
        foreach (WidgetFrontendInitScriptHelper::WIDGET_INIT_VARIABLES as $variable) {
            $this->assertStringContainsString('{' . $variable . '}', $code);
        }
    }

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
            'WIDGET_TYPE' => 'standard',
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
            'AVAILABLE_PRODUCT_TYPES' => ['CONVENIENT_INSTALLMENTS', 'LEASING'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $this->assertStringNotContainsString(
            $forbiddenOutput,
            WidgetFrontendInitScriptHelper::renderWidgetInitScript(
                $widgetInitCode,
                $widgetInitParams,
                $widgetInitVariables
            )
        );
    }

    /**
     * Regression: defensive JSON flags must apply to *array* params too, not only scalar strings.
     *
     * The rendered output is embedded directly into a `<script>` block on the shop page, so any string buried inside
     * an array (e.g., product names in `productCartDetails` from admin-controlled catalog data) that contains
     * `</script>`, `<`, `>`, `&`, or quote characters must be hex-encoded - otherwise an admin who can edit a product
     * name can inject script content that escapes the surrounding tag. Before this regression test, the helper applied
     * `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` only to the scalar branch and arrays were
     * serialized with default flags - exactly the gap that bypassed XSS protection when callers stopped pre-serializing
     * arrays.
     */
    public function testRenderWidgetInitScriptEscapesXssInArrayValues(): void
    {
        $maliciousProductName = '</script><script>alert(1)</script>';

        $widgetInitCode = 'productCartDetails: {PRODUCT_CART_DETAILS}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'standard',
            'OFFER_TYPES' => ['CONVENIENT_INSTALLMENTS'],
            'EMBED_METHOD' => 'INSERT',
            'SHOW_PROVIDER_LOGOS' => false,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
        $widgetInitVariables = [
            'WIDGET_SCRIPT_URL' => 'https://example.com/widget.js',
            'PRODUCT_ID' => 1,
            'PRODUCT_PRICE' => 100,
            'AVAILABLE_PRODUCT_TYPES' => ['CONVENIENT_INSTALLMENTS'],
            'PRODUCT_CART_DETAILS' => [
                'totalAmount' => 100,
                'products' => [
                    ['name' => $maliciousProductName, 'quantity' => 1, 'price' => 100],
                ],
            ],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'PLN',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $rendered = WidgetFrontendInitScriptHelper::renderWidgetInitScript(
            $widgetInitCode,
            $widgetInitParams,
            $widgetInitVariables
        );

        // The literal injection vector must not survive serialization.
        $this->assertStringNotContainsString('</script>', $rendered);
        $this->assertStringNotContainsString('<script>', $rendered);
        // Hex-encoded equivalents are present, proving the flags fired on the array branch.
        $this->assertStringContainsString('\\u003C', $rendered);
        $this->assertStringContainsString('\\u003E', $rendered);
    }

    public function testRenderWidgetInitScriptRendersNumericValuesWithoutQuotes(): void
    {
        $widgetInitCode = 'productId: {PRODUCT_ID}, productPrice: {PRODUCT_PRICE}';
        $widgetInitParams = [
            'WIDGET_KEY' => 'key',
            'WIDGET_PRICE_SELECTOR' => '.price',
            'WIDGET_TARGET_SELECTOR' => '#target',
            'WIDGET_PRICE_OBSERVER_SELECTOR' => '.observer',
            'WIDGET_PRICE_OBSERVER_LEVEL' => 1,
            'WIDGET_TYPE' => 'standard',
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
            'AVAILABLE_PRODUCT_TYPES' => ['CONVENIENT_INSTALLMENTS', 'LEASING'],
            'PRODUCT_CART_DETAILS' => [],
            'LANGUAGE' => 'en',
            'CURRENCY' => 'USD',
            'SHOP_ENVIRONMENT' => 'production',
        ];

        $widgetInitScript = WidgetFrontendInitScriptHelper::renderWidgetInitScript(
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
