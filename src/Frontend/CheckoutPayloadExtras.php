<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Frontend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Frontend;

/**
 * Checkout-only fields merged onto the paywall init config by {@see PaywallConfigBuilder::buildCheckoutPayload()}.
 *
 * The plugin gathers these from the platform (cart, shop environment, labels); the SDK owns the payload shape, so
 * every checkout entry point of a plugin emits the same keys in the same order.
 */
final class CheckoutPayloadExtras
{
    /**
     * @param string $pluginVersion Version of the plugin rendering the checkout
     * @param array<string, mixed>|null $cart Paywall cart ({@see PaywallCartSerializer::serialize()}), or null when the
     *                                        cart could not be built
     * @param array<string, mixed>|null $shopEnvironment Frontend shop environment report, or null to omit
     * @param array<string, string>|null $productTypeNames Product type names keyed by type code, or null when unknown
     * @param string $paymentMethodAuth Auth hash of the payment method logo URL
     * @param string|null $paymentMethodLabel Merchant-configured payment method label, or null for the default
     * @param string|null $defaultLogoUrl Fallback payment method logo URL, or null to omit
     * @param bool $isActive Whether the payment method is active
     * @param array<string, mixed> $additional Platform-specific fields appended after the standard ones (they cannot
     *                                         override paywall config or standard extras keys)
     */
    public function __construct(
        public readonly string $pluginVersion,
        public readonly ?array $cart = null,
        public readonly ?array $shopEnvironment = null,
        public readonly ?array $productTypeNames = null,
        public readonly string $paymentMethodAuth = '',
        public readonly ?string $paymentMethodLabel = null,
        public readonly ?string $defaultLogoUrl = null,
        public readonly bool $isActive = true,
        public readonly array $additional = []
    ) {
    }

    /**
     * Returns the standard fields followed by the platform-specific ones.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $standard = [
            'isActive' => $this->isActive,
            'pluginVersion' => $this->pluginVersion,
            'cart' => $this->cart,
            'shopEnvironment' => $this->shopEnvironment,
            'productTypeNames' => $this->productTypeNames,
            'paymentMethodAuth' => $this->paymentMethodAuth,
            'paymentMethodLabel' => $this->paymentMethodLabel,
            'defaultLogoUrl' => $this->defaultLogoUrl,
        ];

        return $standard + $this->additional;
    }
}
