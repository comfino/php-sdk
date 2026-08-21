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

use Comfino\Frontend\ProductWidgetScriptHelper;
use PHPUnit\Framework\TestCase;

final class ProductWidgetScriptHelperTest extends TestCase
{
    public function testGetBridgeScriptUrlDefaultsToGenericBridge(): void
    {
        $this->assertSame(
            'https://sdk.comfino.pl/product/v1/comfino-widget.min.js',
            ProductWidgetScriptHelper::getScriptUrl(false)
        );
        $this->assertSame(
            'https://sdk.craty.pl/product/v1/comfino-widget.min.js',
            ProductWidgetScriptHelper::getScriptUrl(true)
        );
        $this->assertSame(
            'https://sdk.comfino.pl/product/v1/comfino-widget.min.js',
            ProductWidgetScriptHelper::getScriptUrl(false, 'generic')
        );
    }

    public function testGetBridgeScriptUrlUsesPerPlatformBridge(): void
    {
        $this->assertSame(
            'https://sdk.comfino.pl/product/v1/comfino-prestashop-widget.min.js',
            ProductWidgetScriptHelper::getScriptUrl(false, 'PrestaShop')
        );
        $this->assertSame(
            'https://sdk.comfino.pl/product/v3/comfino-woocommerce-widget.min.js',
            ProductWidgetScriptHelper::getScriptUrl(false, 'woocommerce', 3)
        );
    }

    public function testBuildConfigFiltersUnknownKeysAndNulls(): void
    {
        $config = ProductWidgetScriptHelper::buildConfig([
            'sdkScriptUrl' => 'https://sdk.comfino.pl/v1/comfino-sdk.min.js',
            'widgetKey' => 'wk',
            'priceObserverLevel' => 0,
            'productId' => null,          // dropped (null)
            'SECRET_API_KEY' => 'nope',   // dropped (unknown key)
        ]);

        $this->assertArrayHasKey('sdkScriptUrl', $config);
        $this->assertArrayHasKey('widgetKey', $config);
        $this->assertArrayHasKey('priceObserverLevel', $config);
        $this->assertArrayNotHasKey('productId', $config);
        $this->assertArrayNotHasKey('SECRET_API_KEY', $config);
    }

    public function testRenderScriptEmitsConfigBlockAndScriptTag(): void
    {
        $markup = ProductWidgetScriptHelper::renderScript(
            ['widgetKey' => 'wk', 'sdkScriptUrl' => 'https://sdk.comfino.pl/v1/comfino-sdk.min.js'],
            'https://sdk.comfino.pl/product/v1/comfino-widget.min.js'
        );

        $this->assertStringContainsString('<script type="application/json" id="comfino-widget-config">', $markup);
        $this->assertStringContainsString('"widgetKey":"wk"', $markup);
        $this->assertStringContainsString('src="https://sdk.comfino.pl/product/v1/comfino-widget.min.js"', $markup);
    }

    public function testRenderScriptIsXssSafe(): void
    {
        $markup = ProductWidgetScriptHelper::renderScript(
            ['widgetKey' => '</script><script>alert(1)</script>'],
            'https://sdk.comfino.pl/product/v1/comfino-widget.min.js'
        );

        // The closing tag from the value must be escaped, leaving only the two structural script tags.
        $this->assertStringNotContainsString('</script><script>alert(1)', $markup);
        $this->assertSame(2, substr_count($markup, '<script'));
    }
}
