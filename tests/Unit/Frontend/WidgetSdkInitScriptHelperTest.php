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

use Comfino\Frontend\WidgetSdkInitScriptHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WidgetSdkInitScriptHelperTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function validParams(): array
    {
        return [
            'WIDGET_KEY' => 'sdk-widget-key',
            'WIDGET_TARGET_SELECTOR' => '#comfino-widget',
            'WIDGET_TYPE' => 'banner',
            'OFFER_TYPES' => ['INSTALLMENTS_ZERO_PERCENT'],
            'ENVIRONMENT' => 'production',
            'SHOW_PROVIDER_LOGOS' => true,
            'HAS_PRICE_INPUT' => false,
            'CUSTOM_BANNER_CSS_URL' => '',
            'CUSTOM_CALCULATOR_CSS_URL' => '',
        ];
    }

    /** @return array<string, mixed> */
    private static function validVariables(): array
    {
        return [
            'WIDGET_SCRIPT_URL' => 'https://example.com/comfino-sdk.min.js',
            'PRODUCT_ID' => 42,
            'PRODUCT_PRICE' => 19900,
            'AVAILABLE_PRODUCT_TYPES' => ['INSTALLMENTS_ZERO_PERCENT'],
            'PRODUCT_CART_DETAILS' => ['items' => []],
            'LANGUAGE' => 'pl',
            'CURRENCY' => 'PLN',
            'SHOP_ENVIRONMENT' => ['platform' => 'magento2'],
        ];
    }

    public function testRenderWidgetInitScriptReplacesAllPlaceholders(): void
    {
        $script = WidgetSdkInitScriptHelper::renderWidgetInitScript(
            WidgetSdkInitScriptHelper::getInitialWidgetCode(),
            self::validParams(),
            self::validVariables()
        );

        foreach (WidgetSdkInitScriptHelper::WIDGET_INIT_PARAMS as $param) {
            $this->assertStringNotContainsString('{' . $param . '}', $script);
        }

        foreach (array_keys(self::validVariables()) as $var) {
            $this->assertStringNotContainsString('{' . $var . '}', $script);
        }
    }

    public function testRenderWidgetInitScriptContainsInjectedValues(): void
    {
        $script = WidgetSdkInitScriptHelper::renderWidgetInitScript(
            WidgetSdkInitScriptHelper::getInitialWidgetCode(),
            self::validParams(),
            self::validVariables()
        );

        $this->assertStringContainsString('sdk-widget-key', $script);
        $this->assertStringContainsString('comfino-sdk.min.js', $script);
        $this->assertStringContainsString('19900', $script);
        $this->assertStringContainsString('production', $script);
    }

    public function testRenderThrowsOnMissingParams(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WidgetSdkInitScriptHelper::renderWidgetInitScript(
            WidgetSdkInitScriptHelper::getInitialWidgetCode(),
            ['WIDGET_KEY' => 'k'],   // incomplete
            self::validVariables()
        );
    }

    public function testRenderThrowsOnMissingVariables(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WidgetSdkInitScriptHelper::renderWidgetInitScript(
            WidgetSdkInitScriptHelper::getInitialWidgetCode(),
            self::validParams(),
            ['WIDGET_SCRIPT_URL' => 'https://example.com/sdk.js']  // incomplete
        );
    }

    public function testBoolParamRenderedAsJsLiteral(): void
    {
        $paramsTrue  = array_merge(self::validParams(), ['SHOW_PROVIDER_LOGOS' => true]);
        $paramsFalse = array_merge(self::validParams(), ['SHOW_PROVIDER_LOGOS' => false]);

        $scriptTrue  = WidgetSdkInitScriptHelper::renderWidgetInitScript(
            WidgetSdkInitScriptHelper::getInitialWidgetCode(),
            $paramsTrue,
            self::validVariables()
        );
        $scriptFalse = WidgetSdkInitScriptHelper::renderWidgetInitScript(
            WidgetSdkInitScriptHelper::getInitialWidgetCode(),
            $paramsFalse,
            self::validVariables()
        );

        $this->assertStringNotContainsString("'true'", $scriptTrue);
        $this->assertStringNotContainsString("'false'", $scriptFalse);
    }

    public function testNullVariableRenderedAsJsNull(): void
    {
        $vars = array_merge(self::validVariables(), ['PRODUCT_ID' => null]);
        $script = WidgetSdkInitScriptHelper::renderWidgetInitScript(
            WidgetSdkInitScriptHelper::getInitialWidgetCode(),
            self::validParams(),
            $vars
        );

        $this->assertStringNotContainsString("'null'", $script);
        $this->assertStringContainsString('null', $script);
    }

    public function testInitScriptRequiresUpdateReturnsFalseForCurrentCode(): void
    {
        $this->assertFalse(
            WidgetSdkInitScriptHelper::initScriptRequiresUpdate(
                WidgetSdkInitScriptHelper::getInitialWidgetCode()
            )
        );
    }

    public function testInitScriptRequiresUpdateReturnsTrueForModifiedCode(): void
    {
        $this->assertTrue(
            WidgetSdkInitScriptHelper::initScriptRequiresUpdate('outdated script content')
        );
    }

    public function testGetInitialWidgetCodeHashIsConsistent(): void
    {
        $this->assertSame(
            WidgetSdkInitScriptHelper::getInitialWidgetCodeHash(),
            WidgetSdkInitScriptHelper::getInitialWidgetCodeHash()
        );
    }

    public function testGetInitialWidgetCodeReturnsNonEmptyString(): void
    {
        $this->assertNotEmpty(WidgetSdkInitScriptHelper::getInitialWidgetCode());
    }
}
