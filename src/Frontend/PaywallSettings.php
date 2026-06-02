<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Frontend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Frontend;

use JsonSerializable;

/**
 * In-iframe paywall display settings, mirroring the frontend SDK's IframePaywallSettings contract.
 *
 * Plugins build one instance and pass it to PaywallConfig; the serialized array drops straight into the frontend SDK's
 * paywall options without further manipulation.
 */
final class PaywallSettings implements JsonSerializable
{
    /**
     * @param string $language ISO 639-1 UI language code (e.g. 'pl', 'en')
     * @param string $currency ISO 4217 currency code (e.g. 'PLN')
     * @param int $priceModifier Integer offset added to the displayed cart total in the smallest currency unit
     * @param string|null $productDetailsApiPath URL path used by the paywall iframe to fetch deferred offer details
     * @param string|null $customPaywallCss Custom CSS URL injected into the paywall iframe (validated server-side)
     */
    public function __construct(
        public readonly string $language,
        public readonly string $currency,
        public readonly int $priceModifier = 0,
        public readonly ?string $productDetailsApiPath = null,
        public readonly ?string $customPaywallCss = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'currency' => $this->currency,
            'priceModifier' => $this->priceModifier,
            'productDetailsApiPath' => $this->productDetailsApiPath,
            'customPaywallCss' => $this->customPaywallCss,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
