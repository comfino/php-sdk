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

use Comfino\Frontend\CapabilityResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapabilityResolverTest extends TestCase
{
    public function testHyvaFamily(): void
    {
        $caps = CapabilityResolver::fromThemeFamily('hyva');

        $this->assertFalse($caps['knockout']);
        $this->assertTrue($caps['alpine']);
        $this->assertTrue($caps['tailwind']);
        $this->assertFalse($caps['requirejs']);
        $this->assertFalse($caps['jquery']);
    }

    public function testLumaFamily(): void
    {
        $caps = CapabilityResolver::fromThemeFamily('luma');

        $this->assertTrue($caps['knockout']);
        $this->assertFalse($caps['alpine']);
        $this->assertFalse($caps['tailwind']);
        $this->assertTrue($caps['requirejs']);
        $this->assertTrue($caps['jquery']);
    }

    public function testBlankFamilyMatchesLuma(): void
    {
        $this->assertSame(
            CapabilityResolver::fromThemeFamily('luma'),
            CapabilityResolver::fromThemeFamily('blank')
        );
    }

    public function testClassicFamily(): void
    {
        $caps = CapabilityResolver::fromThemeFamily('classic');

        $this->assertFalse($caps['knockout']);
        $this->assertFalse($caps['alpine']);
        $this->assertFalse($caps['tailwind']);
        $this->assertFalse($caps['requirejs']);
        $this->assertTrue($caps['jquery']);
    }

    public function testStorefrontFamilyMatchesClassic(): void
    {
        $this->assertSame(
            CapabilityResolver::fromThemeFamily('classic'),
            CapabilityResolver::fromThemeFamily('storefront')
        );
    }

    #[DataProvider('unknownFamilyProvider')]
    public function testUnknownFamilyReturnsAllFalse(string $family): void
    {
        $caps = CapabilityResolver::fromThemeFamily($family);

        $this->assertFalse($caps['knockout']);
        $this->assertFalse($caps['alpine']);
        $this->assertFalse($caps['tailwind']);
        $this->assertFalse($caps['requirejs']);
        $this->assertFalse($caps['jquery']);
    }

    /** @return array<string, array{string}> */
    public static function unknownFamilyProvider(): array
    {
        return [
            'custom' => ['custom'],
            'unknown' => ['unknown'],
            'empty' => [''],
        ];
    }

    public function testReturnedArrayHasExpectedKeys(): void
    {
        $keys = array_keys(CapabilityResolver::fromThemeFamily('hyva'));

        $this->assertSame(['knockout', 'alpine', 'tailwind', 'requirejs', 'jquery'], $keys);
    }
}
