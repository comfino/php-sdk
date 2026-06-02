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
use PHPUnit\Framework\TestCase;

final class PaywallConfigTest extends TestCase
{
    public function testGetAsArrayContainsAllBaseKeys(): void
    {
        $config = new PaywallConfig(
            authToken: 'token',
            loanAmount: 10000,
            environment: 'production',
            sdkScriptUrl: 'https://example.com/sdk.js',
            allowedProductTypes: null
        );

        $array = $config->getAsArray();

        $this->assertArrayHasKey('authToken', $array);
        $this->assertArrayHasKey('loanAmount', $array);
        $this->assertArrayHasKey('environment', $array);
        $this->assertArrayHasKey('sdkScriptUrl', $array);
        $this->assertArrayHasKey('allowedProductTypes', $array);
        $this->assertArrayHasKey('directRedirect', $array);
        $this->assertArrayHasKey('paywallSettings', $array);
    }

    public function testGetAsArrayIncludesCreditorsAndAllowedProductsConfig(): void
    {
        $creditors = ['PAY_LATER' => ['twisto', 'pragmago'], 'LEASING' => ['leaselink']];
        $allowedProductsConfig = [
            ['type' => 'PAY_LATER', 'maxTerm' => 6],
            ['type' => 'INSTALLMENTS_ZERO_PERCENT', 'minTerm' => 3],
        ];

        $config = new PaywallConfig(
            authToken: 'token',
            loanAmount: 50000,
            environment: 'sandbox',
            sdkScriptUrl: 'https://example.com/sdk.js',
            allowedProductTypes: ['PAY_LATER'],
            directRedirect: false,
            creditors: $creditors,
            allowedProductsConfig: $allowedProductsConfig
        );

        $array = $config->getAsArray();

        $this->assertArrayHasKey('creditors', $array);
        $this->assertArrayHasKey('allowedProductsConfig', $array);
        $this->assertSame($creditors, $array['creditors']);
        $this->assertSame($allowedProductsConfig, $array['allowedProductsConfig']);
    }

    public function testGetAsArrayCreditorsIsNullWhenNotProvided(): void
    {
        $config = new PaywallConfig(
            authToken: 'token',
            loanAmount: 10000,
            environment: 'production',
            sdkScriptUrl: 'https://example.com/sdk.js',
            allowedProductTypes: null
        );

        $array = $config->getAsArray();

        $this->assertNull($array['creditors']);
        $this->assertNull($array['allowedProductsConfig']);
    }

    public function testGetAsArrayWithNullCreditorsAndAllowedProductsConfig(): void
    {
        $config = new PaywallConfig(
            authToken: 'tok',
            loanAmount: 1000,
            environment: 'production',
            sdkScriptUrl: 'https://example.com/sdk.js',
            allowedProductTypes: null,
            creditors: null,
            allowedProductsConfig: null
        );

        $array = $config->getAsArray();

        $this->assertArrayHasKey('creditors', $array);
        $this->assertArrayHasKey('allowedProductsConfig', $array);
        $this->assertNull($array['creditors']);
        $this->assertNull($array['allowedProductsConfig']);
    }
}
