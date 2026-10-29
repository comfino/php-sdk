<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Frontend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Frontend;

use Comfino\Api\Dto\Plugin\ShopTheme;
use Comfino\Frontend\AbstractShopEnvironmentBuilder;
use Comfino\Frontend\CapabilityResolver;
use Comfino\Frontend\ThemeFamilyRules;
use Comfino\Platform\PlatformInfoInterface;
use PHPUnit\Framework\TestCase;

final class AbstractShopEnvironmentBuilderTest extends TestCase
{
    private function makePlatformInfo(): PlatformInfoInterface
    {
        $platformInfo = $this->createMock(PlatformInfoInterface::class);
        $platformInfo->method('getVersion')->willReturn('2.4.7');
        $platformInfo->method('getPluginVersion')->willReturn('4.0.0');
        $platformInfo->method('getDomain')->willReturn('myshop.pl');
        $platformInfo->method('getLanguage')->willReturn('pl');
        $platformInfo->method('getCurrency')->willReturn('PLN');

        return $platformInfo;
    }

    private function makeBuilder(?PlatformInfoInterface $platformInfo = null): AbstractShopEnvironmentBuilder
    {
        $platformInfo ??= $this->makePlatformInfo();

        return new class ($platformInfo, new ThemeFamilyRules()) extends AbstractShopEnvironmentBuilder {
            protected function getPlatformIdentifier(): string
            {
                return 'magento';
            }

            protected function getPlatformName(): string
            {
                return 'Magento';
            }

            protected function detectTheme(): ShopTheme
            {
                return new ShopTheme('Hyva/default', 'hyva', ['Magento/blank'], false);
            }

            protected function detectEdition(): string
            {
                return 'community';
            }
        };
    }

    public function testBuildForFrontendExposesOnlyThemeFamily(): void
    {
        $env = $this->makeBuilder()->buildForFrontend();

        $this->assertSame('magento', $env['platform']);
        $this->assertSame('Magento', $env['platformName']);
        $this->assertSame('myshop.pl', $env['platformDomain']);
        $this->assertSame('pl', $env['language']);
        $this->assertSame('PLN', $env['currency']);
        $this->assertSame(['family' => 'hyva'], $env['theme']);
        $this->assertArrayNotHasKey('pageContext', $env);
    }

    public function testBuildForFrontendIncludesPageContextWhenProvided(): void
    {
        $env = $this->makeBuilder()->buildForFrontend(['type' => 'product', 'productId' => 42]);

        $this->assertSame(['type' => 'product', 'productId' => 42], $env['pageContext']);
    }

    public function testBuildForBackendReportPopulatesAllFields(): void
    {
        $report = $this->makeBuilder()->buildForBackendReport('https://myshop.pl/p/test', ['note' => 'x']);

        $this->assertSame('magento', $report->platform);
        $this->assertSame('Magento', $report->platformName);
        $this->assertSame('2.4.7', $report->platformVersion);
        $this->assertSame('community', $report->platformEdition);
        $this->assertSame('myshop.pl', $report->platformDomain);
        $this->assertSame('4.0.0', $report->pluginVersion);
        $this->assertSame('Hyva/default', $report->theme->code);
        $this->assertSame('hyva', $report->theme->family);
        $this->assertSame('pl', $report->language);
        $this->assertSame('PLN', $report->currency);
        $this->assertSame(CapabilityResolver::fromThemeFamily('hyva'), $report->capabilities);
        $this->assertSame('https://myshop.pl/p/test', $report->testProductUrl);
        $this->assertSame(['note' => 'x'], $report->meta);
    }

    public function testBuildReportArrayProducesSnakeCaseShape(): void
    {
        $array = $this->makeBuilder()->buildReportArray('https://myshop.pl/p/test');

        $this->assertSame('magento', $array['platform']);
        $this->assertSame('Magento', $array['platform_name']);
        $this->assertSame('2.4.7', $array['platform_version']);
        $this->assertSame('community', $array['platform_edition']);
        $this->assertSame('myshop.pl', $array['platform_domain']);
        $this->assertSame('4.0.0', $array['plugin_version']);
        $this->assertSame(['code' => 'Hyva/default', 'family' => 'hyva', 'parents' => ['Magento/blank'], 'is_pwa' => false], $array['theme']);
        $this->assertSame('pl', $array['language']);
        $this->assertSame('PLN', $array['currency']);
        $this->assertSame(CapabilityResolver::fromThemeFamily('hyva'), $array['capabilities']);
        $this->assertSame('https://myshop.pl/p/test', $array['test_product_url']);
        $this->assertSame([], $array['meta']);
    }
}
