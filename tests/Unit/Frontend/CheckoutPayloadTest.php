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

use Comfino\Frontend\CheckoutPayloadExtras;
use Comfino\Frontend\PaywallConfig;
use Comfino\Frontend\PaywallConfigBuilder;
use PHPUnit\Framework\TestCase;

final class CheckoutPayloadTest extends TestCase
{
    public function testPayloadIsTheConfigFollowedByTheExtrasInAFixedOrder(): void
    {
        $config = $this->createConfig();
        $extras = new CheckoutPayloadExtras(
            pluginVersion: '4.1.0',
            cart: ['products' => []],
            shopEnvironment: ['platform' => 'magento'],
            productTypeNames: ['PAY_LATER' => 'Pay later'],
            paymentMethodAuth: 'hash',
            paymentMethodLabel: 'Pay with ComfinoPay',
            defaultLogoUrl: 'https://cdn.example/logo.svg'
        );

        $payload = PaywallConfigBuilder::buildCheckoutPayload($config, $extras);

        self::assertSame(
            array_merge(array_keys($config->getAsArray()), [
                'isActive',
                'pluginVersion',
                'cart',
                'shopEnvironment',
                'productTypeNames',
                'paymentMethodAuth',
                'paymentMethodLabel',
                'defaultLogoUrl',
            ]),
            array_keys($payload)
        );
        self::assertTrue($payload['isActive']);
        self::assertSame('4.1.0', $payload['pluginVersion']);
        self::assertSame(['products' => []], $payload['cart']);
        self::assertSame('auth', $payload['authToken']);
        self::assertSame('track', $payload['trackId']);
    }

    public function testOptionalExtrasDefaultToNull(): void
    {
        $payload = PaywallConfigBuilder::buildCheckoutPayload($this->createConfig(), new CheckoutPayloadExtras('4.1.0'));

        self::assertNull($payload['cart']);
        self::assertNull($payload['shopEnvironment']);
        self::assertNull($payload['productTypeNames']);
        self::assertSame('', $payload['paymentMethodAuth']);
        self::assertNull($payload['paymentMethodLabel']);
        self::assertNull($payload['defaultLogoUrl']);
    }

    public function testAdditionalFieldsAreAppendedButCannotOverrideStandardKeys(): void
    {
        $payload = PaywallConfigBuilder::buildCheckoutPayload(
            $this->createConfig(),
            new CheckoutPayloadExtras(
                pluginVersion: '4.1.0',
                additional: ['authToken' => 'forged', 'pluginVersion' => '0.0.0', 'customField' => 42]
            )
        );

        self::assertSame('auth', $payload['authToken']);
        self::assertSame('4.1.0', $payload['pluginVersion']);
        self::assertSame(42, $payload['customField']);
        self::assertSame('customField', array_key_last($payload));
    }

    private function createConfig(): PaywallConfig
    {
        return new PaywallConfig(
            authToken: 'auth',
            loanAmount: 10000,
            environment: 'sandbox',
            sdkScriptUrl: 'https://sdk.example/comfino-sdk.min.js',
            allowedProductTypes: null,
            trackId: 'track'
        );
    }
}
