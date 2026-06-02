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

use Comfino\Auth\PaywallAuthKeyGenerator;
use Throwable;

/**
 * Builds the configuration object required to initialize the Comfino Paywall SDK.
 *
 * Contains only static factory methods — no state.
 */
final class PaywallConfigBuilder
{
    /**
     * Builds the paywall initialization config object.
     *
     * @param string $apiKey Comfino API key
     * @param string $widgetKey Comfino widget key (UUIDv4)
     * @param int $loanAmount Cart total in grosze (smallest currency unit)
     * @param bool $sandboxMode Whether to use the sandbox environment
     * @param string $sdkScriptUrl Full URL of the Comfino SDK JavaScript bundle
     * @param string[]|null $allowedProductTypes Whitelist of allowed loan product type codes or null to allow all types
     * @param bool $directRedirect Whether to hide paywall iframe and submit order with default offer
     * @param PaywallSettings|null $paywallSettings In-iframe paywall display settings, or null to omit
     * @param array<string, string[]>|null $creditors Available creditors keyed by product type code, or null to omit
     * @param array<int, array{type: string, maxTerm?: int, minTerm?: int, terms?: int[]}>|null $allowedProductsConfig
     *        Per-product-type term constraints, or null to omit
     *
     * @return PaywallConfig The paywall initialization config object
     */
    public static function buildConfig(
        string $apiKey,
        string $widgetKey,
        int $loanAmount,
        bool $sandboxMode,
        string $sdkScriptUrl,
        ?array $allowedProductTypes,
        bool $directRedirect = false,
        ?PaywallSettings $paywallSettings = null,
        ?array $creditors = null,
        ?array $allowedProductsConfig = null
    ): PaywallConfig {
        try {
            $authToken = (new PaywallAuthKeyGenerator())->generateAuthKey($widgetKey, $apiKey);
        } catch (Throwable) {
            $authToken = '';
        }

        return new PaywallConfig(
            authToken: $authToken,
            loanAmount: $loanAmount,
            environment: $sandboxMode ? 'sandbox' : 'production',
            sdkScriptUrl: $sdkScriptUrl,
            allowedProductTypes: $allowedProductTypes,
            directRedirect: $directRedirect,
            paywallSettings: $paywallSettings,
            creditors: $creditors,
            allowedProductsConfig: $allowedProductsConfig
        );
    }

    /**
     * Returns true when the paywall should be rendered for the given cart state.
     *
     * The paywall is suppressed when:
     *   - The loan amount is zero or negative (empty cart or invalid price), or
     *   - The allowed product types list is explicitly empty (no types are available).
     *
     * A null $allowedProductTypes means "no filter" — all types are allowed.
     *
     * @param int $loanAmount Cart total in grosze
     * @param string[]|null $allowedProductTypes Filtered product type list, or null
     */
    public static function shouldShowPaywall(int $loanAmount, ?array $allowedProductTypes): bool
    {
        if ($loanAmount <= 0) {
            return false;
        }

        if ($allowedProductTypes === []) {
            return false;
        }

        return true;
    }
}
