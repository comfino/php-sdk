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

use Comfino\Api\Dto\Plugin\ShopEnvironmentReport;
use Comfino\Api\Dto\Plugin\ShopTheme;
use Comfino\Platform\PlatformInfoInterface;

/**
 * Base shop-environment builder that produces two distinct environment payloads:
 *
 *   - {@see buildForFrontend()}: minimal, browser-safe payload for the widget / paywall init
 *     script. Contains only what the CDN-served SDK needs to pick a selector profile
 *     (platform identifier, theme family, locale, optional page context). No sensitive
 *     fingerprinting data.
 *
 *   - {@see buildForBackendReport()}: full structured environment shipped server-to-server
 *     via the dedicated shop-environment reporting endpoint. Carries platform/plugin versions,
 *     edition, raw theme code + parent chain, capability hints, and store metadata.
 *
 * The split is a deliberate security boundary. Adding a new field requires deciding which side it belongs on; default
 * to the backend report unless the CDN SDK has a concrete runtime need for it.
 *
 * Subclasses provide the platform-specific theme detection and identifier logic.
 */
abstract class AbstractShopEnvironmentBuilder
{
    public function __construct(
        protected readonly PlatformInfoInterface $platformInfo,
        protected readonly ThemeFamilyRules $rules,
    ) {
    }

    /**
     * Builds a browser-safe shop environment array for the widget / paywall init script.
     *
     * @param array{type: string, productId?: int|string|null}|null $pageContext Optional page context passed through
     *                                                                           to the frontend SDK
     *
     * @return array<string, mixed> The browser-safe environment payload
     */
    public function buildForFrontend(?array $pageContext = null): array
    {
        $env = [
            'platform' => $this->getPlatformIdentifier(),
            'platformName' => $this->getPlatformName(),
            'platformDomain' => $this->platformInfo->getDomain(),
            'theme' => ['family' => $this->detectTheme()->family],
            'language' => $this->platformInfo->getLanguage(),
            'currency' => $this->platformInfo->getCurrency(),
        ];

        if ($pageContext !== null) {
            $env['pageContext'] = $pageContext;
        }

        return $env;
    }

    /**
     * Builds a full shop environment report for server-to-server reporting.
     *
     * @param string|null $testProductUrl URL of a test product for selector verification
     * @param array<string, mixed> $meta Additional platform-specific metadata
     */
    public function buildForBackendReport(?string $testProductUrl = null, array $meta = []): ShopEnvironmentReport
    {
        $theme = $this->detectTheme();

        return new ShopEnvironmentReport(
            platform: $this->getPlatformIdentifier(),
            platformName: $this->getPlatformName(),
            platformVersion: $this->platformInfo->getVersion(),
            platformEdition: $this->detectEdition(),
            platformDomain: $this->platformInfo->getDomain(),
            pluginVersion: $this->platformInfo->getPluginVersion(),
            theme: $theme,
            language: $this->platformInfo->getLanguage(),
            currency: $this->platformInfo->getCurrency(),
            capabilities: CapabilityResolver::fromThemeFamily($theme->family),
            testProductUrl: $testProductUrl,
            meta: $meta
        );
    }

    /**
     * Returns the platform machine identifier (e.g. "magento", "prestashop", "woocommerce").
     */
    abstract protected function getPlatformIdentifier(): string;

    /**
     * Returns the human-readable platform name (e.g. "Magento", "PrestaShop", "WooCommerce").
     */
    abstract protected function getPlatformName(): string;

    /**
     * Detects the active frontend theme and returns its DTO.
     *
     * Implementations should populate ShopTheme::$code with the raw theme code, ShopTheme::$parents with the full
     * inheritance chain, and ShopTheme::$family with the normalized family resolved via the ThemeFamilyRules registry.
     */
    abstract protected function detectTheme(): ShopTheme;

    /**
     * Detects the platform edition.
     *
     * @return string|null Platform edition string (e.g. "community", "enterprise"), or null
     */
    abstract protected function detectEdition(): ?string;
}
