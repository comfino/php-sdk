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

use Comfino\Frontend\PaywallSettings;
use PHPUnit\Framework\TestCase;

final class PaywallSettingsTest extends TestCase
{
    public function testConstructorAssignsRequiredFields(): void
    {
        $settings = new PaywallSettings('pl', 'PLN');

        $this->assertSame('pl', $settings->language);
        $this->assertSame('PLN', $settings->currency);
    }

    public function testPriceModifierDefaultsToZero(): void
    {
        $this->assertSame(0, (new PaywallSettings('pl', 'PLN'))->priceModifier);
    }

    public function testPriceModifierIsAssigned(): void
    {
        $this->assertSame(-500, (new PaywallSettings('pl', 'PLN', -500))->priceModifier);
    }

    public function testOptionalFieldsDefaultToNull(): void
    {
        $settings = new PaywallSettings('en', 'EUR');

        $this->assertNull($settings->productDetailsApiPath);
        $this->assertNull($settings->customPaywallCss);
    }

    public function testOptionalFieldsAreAssigned(): void
    {
        $settings = new PaywallSettings('pl', 'PLN', 0, '/api/products', 'https://cdn.example.com/paywall.css');

        $this->assertSame('/api/products', $settings->productDetailsApiPath);
        $this->assertSame('https://cdn.example.com/paywall.css', $settings->customPaywallCss);
    }

    public function testToArrayReturnsAllKeys(): void
    {
        $settings = new PaywallSettings('pl', 'PLN', 100, '/api/products', 'https://cdn.example.com/paywall.css');
        $array = $settings->toArray();

        $this->assertSame('pl', $array['language']);
        $this->assertSame('PLN', $array['currency']);
        $this->assertSame(100, $array['priceModifier']);
        $this->assertSame('/api/products', $array['productDetailsApiPath']);
        $this->assertSame('https://cdn.example.com/paywall.css', $array['customPaywallCss']);
    }

    public function testToArrayIncludesNullOptionals(): void
    {
        $array = (new PaywallSettings('pl', 'PLN'))->toArray();

        $this->assertArrayHasKey('productDetailsApiPath', $array);
        $this->assertArrayHasKey('customPaywallCss', $array);
        $this->assertNull($array['productDetailsApiPath']);
        $this->assertNull($array['customPaywallCss']);
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $settings = new PaywallSettings('pl', 'PLN', 50, '/api/prod', null);

        $this->assertSame($settings->toArray(), $settings->jsonSerialize());
    }

    /**
     * @throws \JsonException
     */
    public function testJsonEncodeProducesExpectedJson(): void
    {
        $settings = new PaywallSettings('pl', 'PLN', 0);
        $json = json_encode($settings, JSON_THROW_ON_ERROR);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('pl', $decoded['language']);
        $this->assertSame('PLN', $decoded['currency']);
        $this->assertSame(0, $decoded['priceModifier']);
    }
}
