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

use Comfino\Frontend\SdkUrlBuilder;
use PHPUnit\Framework\TestCase;

class SdkUrlBuilderTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private const MANAGED_ENV = [
        'COMFINO_DEV_ENV',
        'COMFINO_DEV_SDK_CDN_BASE_URL',
        'COMFINO_DEV_API_HOST',
    ];

    protected function setUp(): void
    {
        foreach (self::MANAGED_ENV as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("$name=$value");
            }
        }
    }

    public function testReturnsProductionUrlByDefault(): void
    {
        $this->assertSame(
            'https://sdk.comfino.pl/sdk/v1/comfino-sdk.min.js',
            SdkUrlBuilder::getSdkScriptUrl(false)
        );
    }

    public function testReturnsSandboxUrlInSandboxMode(): void
    {
        $this->assertSame(
            'https://sdk.craty.pl/sdk/v1/comfino-sdk.min.js',
            SdkUrlBuilder::getSdkScriptUrl(true)
        );
    }

    public function testHonorsCustomVersionInPath(): void
    {
        $this->assertSame(
            'https://sdk.comfino.pl/sdk/v2/comfino-sdk.min.js',
            SdkUrlBuilder::getSdkScriptUrl(false, false, 2)
        );

        $this->assertSame(
            'https://sdk.comfino.pl/sdk/v3/comfino-sdk.min.js',
            SdkUrlBuilder::getSdkScriptUrl(false, false, 3)
        );
    }

    public function testOverrideIgnoredWhenDevEnvUnset(): void
    {
        // Override env vars are present, but COMFINO_DEV_ENV is not 'TRUE'.
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://widget.craty.pl');
        putenv('COMFINO_DEV_API_HOST=https://api-ecommerce.craty.pl');

        $this->assertSame(
            'https://sdk.comfino.pl/sdk/v1/comfino-sdk.min.js',
            SdkUrlBuilder::getSdkScriptUrl(false, true)
        );
        $this->assertNull(SdkUrlBuilder::getApiHostOverride(true));
    }

    public function testOverrideIgnoredWhenShopOptInDisabled(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://widget.craty.pl');

        // Shop opt-in flag is false.
        $this->assertSame(
            'https://sdk.comfino.pl/sdk/v1/comfino-sdk.min.js',
            SdkUrlBuilder::getSdkScriptUrl(false, false)
        );
    }

    public function testOverrideIgnoredWhenUrlNotAllowListed(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://dev-cdn.example');
        putenv('COMFINO_DEV_API_HOST=https://attacker.example');

        $this->assertSame(
            'https://sdk.comfino.pl/sdk/v1/comfino-sdk.min.js',
            SdkUrlBuilder::getSdkScriptUrl(false, true)
        );
        $this->assertNull(SdkUrlBuilder::getApiHostOverride(true));
    }

    public function testOverrideHonoredForAllowListedHostUnderDevEnv(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://widget.craty.pl');
        putenv('COMFINO_DEV_API_HOST=https://api-ecommerce.craty.pl');

        $this->assertSame(
            'https://widget.craty.pl/sdk/v1/comfino-sdk.min.js',
            SdkUrlBuilder::getSdkScriptUrl(false, true)
        );
        $this->assertSame('https://api-ecommerce.craty.pl', SdkUrlBuilder::getApiHostOverride(true));
    }

    public function testProductWidgetScriptUrlDefaultsToGenericBridge(): void
    {
        $this->assertSame(
            'https://sdk.comfino.pl/product/v1/comfino-widget.min.js',
            SdkUrlBuilder::getProductWidgetScriptUrl(false)
        );

        $this->assertSame(
            'https://sdk.craty.pl/product/v1/comfino-widget.min.js',
            SdkUrlBuilder::getProductWidgetScriptUrl(true)
        );
    }

    public function testProductWidgetScriptUrlUsesPerPlatformBridgeWhenPlatformGiven(): void
    {
        $this->assertSame(
            'https://sdk.comfino.pl/product/v1/comfino-prestashop-widget.min.js',
            SdkUrlBuilder::getProductWidgetScriptUrl(false, 'prestashop')
        );

        $this->assertSame(
            'https://sdk.comfino.pl/product/v2/comfino-magento-hyva-widget.min.js',
            SdkUrlBuilder::getProductWidgetScriptUrl(false, 'magento-hyva', false, 2)
        );
    }

    public function testProductWidgetScriptUrlHonorsAllowListedDevOverride(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://widget.craty.pl');

        $this->assertSame(
            'https://widget.craty.pl/product/v1/comfino-prestashop-widget.min.js',
            SdkUrlBuilder::getProductWidgetScriptUrl(false, 'prestashop', true)
        );
    }

    public function testProductWidgetScriptUrlIgnoresDevOverrideWhenOptInDisabled(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://widget.craty.pl');

        $this->assertSame(
            'https://sdk.comfino.pl/product/v1/comfino-widget.min.js',
            SdkUrlBuilder::getProductWidgetScriptUrl(false, null, false)
        );
    }

    public function testDefaultLogoUrlReturnsProductionOrSandboxHostByDefault(): void
    {
        $this->assertSame(
            'https://sdk.comfino.pl/images/comfino/comfino_logo.svg',
            SdkUrlBuilder::getDefaultLogoUrl(false)
        );

        $this->assertSame(
            'https://sdk.craty.pl/images/comfino/comfino_logo.svg',
            SdkUrlBuilder::getDefaultLogoUrl(true)
        );
    }

    public function testDefaultLogoUrlHonorsAllowListedDevOverride(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=http://sdk-comfino.test:8081');

        $this->assertSame(
            'http://sdk-comfino.test:8081/images/comfino/comfino_logo.svg',
            SdkUrlBuilder::getDefaultLogoUrl(false, true)
        );
    }

    public function testDefaultLogoUrlIgnoresDevOverrideWhenOptInDisabled(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=http://sdk-comfino.test:8081');

        $this->assertSame(
            'https://sdk.comfino.pl/images/comfino/comfino_logo.svg',
            SdkUrlBuilder::getDefaultLogoUrl(false, false)
        );
    }

    public function testDefaultLogoUrlIgnoresDevOverrideWhenUrlNotAllowListed(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');
        putenv('COMFINO_DEV_SDK_CDN_BASE_URL=https://attacker.example');

        $this->assertSame(
            'https://sdk.comfino.pl/images/comfino/comfino_logo.svg',
            SdkUrlBuilder::getDefaultLogoUrl(false, true)
        );
    }
}
