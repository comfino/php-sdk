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

/**
 * Represents the configuration required to initialize the Comfino Paywall SDK.
 */
final class PaywallConfig
{
    /**
     * @param string $authToken Authentication token for the paywall (base64-encoded)
     * @param int $loanAmount Cart total in grosze (cents)
     * @param string $environment Environment identifier ('production' or 'sandbox')
     * @param string $sdkScriptUrl Full URL of the Comfino SDK JavaScript bundle
     * @param string[]|null $allowedProductTypes Whitelist of allowed product types or null for no restriction
     * @param bool $directRedirect Whether to hide paywall iframe and submit order with default offer
     * @param PaywallSettings|null $paywallSettings In-iframe paywall display settings
     * @param array<string, string[]>|null $creditors Available creditors keyed by product type code
     * @param array<int, array{type: string, maxTerm?: int, minTerm?: int, terms?: int[]}>|null $allowedProductsConfig
     *        Per-product-type term constraints for the paywall
     */
    public function __construct(
        public readonly string $authToken,
        public readonly int $loanAmount,
        public readonly string $environment,
        public readonly string $sdkScriptUrl,
        public readonly ?array $allowedProductTypes,
        public readonly bool $directRedirect = false,
        public readonly ?PaywallSettings $paywallSettings = null,
        public readonly ?array $creditors = null,
        public readonly ?array $allowedProductsConfig = null
    ) {
    }

    /**
     * Returns the configuration as an associative array shaped for direct passthrough to the frontend SDK init payload.
     * Plugins should `json_encode` this result and emit it as `window.comfinoSettings` (or platform equivalent) — no
     * further field manipulation required at the module/plugin/platform side.
     *
     * @return array<string, mixed>
     */
    public function getAsArray(): array
    {
        return [
            'authToken' => $this->authToken,
            'loanAmount' => $this->loanAmount,
            'environment' => $this->environment,
            'sdkScriptUrl' => $this->sdkScriptUrl,
            'allowedProductTypes' => $this->allowedProductTypes,
            'directRedirect' => $this->directRedirect,
            'paywallSettings' => $this->paywallSettings?->toArray(),
            'creditors' => $this->creditors,
            'allowedProductsConfig' => $this->allowedProductsConfig,
        ];
    }
}
