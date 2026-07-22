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

use Comfino\Api\SerializerInterface;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Api\Exception\RequestValidationError;

/**
 * Renders the default Comfino product-page widget initialization: a JSON config block plus the CDN-hosted product
 * widget script <script src>.
 *
 * The product-page sibling of the checkout glue (see getCheckoutScriptUrl); it is the recommended default for new
 * integrations, replacing the inline import()/bootstrapWidget script of {@see WidgetSdkInitScriptHelper}. It mirrors
 * how the checkout paywall is already wired (a `<script type="application/json" id="comfino-checkout-config">` block +
 * a checkout `<script src>`):
 *
 *   <script type="application/json" id="comfino-widget-config">{ ...WidgetConfig... }</script>
 *   <script src="https://sdk.comfino.pl/product/v1/comfino-widget.min.js"></script>
 *
 * The product widget script is a classic IIFE (NOT a module): it reads the JSON config, dynamically imports the SDK
 * bundle named by `sdkScriptUrl`, and calls sdk.bootstrapWidget()/bootstrapCalculator() itself. The config therefore
 * MUST include `sdkScriptUrl` (the comfino-sdk.min.js URL) and `widgetKey`.
 *
 * Default = the GENERIC script (`comfino-widget.min.js`), suitable for any platform / external integrator.
 * Comfino-maintained shop plugins pass their platform to {@see getScriptUrl()} to load their dedicated per-platform
 * script (`comfino-<platform>-widget.min.js`) instead; {@see SdkUrlBuilder::getProductWidgetScriptUrl()} adds the
 * dev-environment override layer on top.
 */
class ProductWidgetScriptHelper
{
    /** CDN hosts serving the shop-page product widget script (mirrors the SDK bundle hosts). */
    public const SCRIPT_PRODUCTION_HOST = 'https://sdk.comfino.pl';
    public const SCRIPT_SANDBOX_HOST = 'https://sdk.craty.pl';
    public const DEFAULT_SCRIPT_VERSION = 1;

    /** The DOM id of the JSON config block the product widget script reads by default. */
    public const CONFIG_ELEMENT_ID = 'comfino-widget-config';

    /**
     * Keys allowed in the emitted widget config block — the published WidgetConfig / ComfinoGenericWidgetConfig
     * contract. Unknown keys are dropped so a caller cannot leak arbitrary data into the page.
     */
    public const WIDGET_CONFIG_KEYS = [
        'sdkScriptUrl',
        'environment',
        'widgetKey',
        'loggingToken',
        'trackId',
        'components',
        'container',
        'widgetTargetSelector',
        'priceSelector',
        'priceSelectors',
        'priceCombine',
        'priceAttribute',
        'priceObserverSelector',
        'priceObserverLevel',
        'quantitySelector',
        'triggerSelector',
        'embedMethod',
        'price',
        'priceType',
        'vatRate',
        'bannerCssUrl',
        'calculatorCssUrl',
        'widgetType',
        'offerTypes',
        'availableProductTypes',
        'hasPriceInput',
        'showProviderLogos',
        'language',
        'currency',
        'productId',
        'productCartDetails',
        'shopEnvironment',
    ];

    /**
     * Builds the product-page widget script URL.
     *
     * @param bool $sandboxMode Whether the shop runs in sandbox mode
     * @param string|null $platform Comfino platform id for the dedicated per-platform script (e.g. 'prestashop',
     *                              'woocommerce', 'magento-hyva'); null / '' / 'generic' selects the generic script
     * @param int $version Product widget script major version number (e.g. 1 -> '/product/v1/')
     *
     * @return string The absolute product widget script URL
     */
    public static function getScriptUrl(
        bool $sandboxMode,
        ?string $platform = null,
        int $version = self::DEFAULT_SCRIPT_VERSION
    ): string {
        $host = $sandboxMode ? self::SCRIPT_SANDBOX_HOST : self::SCRIPT_PRODUCTION_HOST;
        $normalizedPlatform = $platform !== null ? strtolower(trim($platform)) : '';

        $file = ($normalizedPlatform === '' || $normalizedPlatform === 'generic')
            ? 'comfino-widget.min.js'
            : 'comfino-' . $normalizedPlatform . '-widget.min.js';

        return $host . '/product/v' . $version . '/' . $file;
    }

    /**
     * Filters an arbitrary values array down to the known WidgetConfig keys, dropping unknown keys and null values (so
     * an omitted option falls through to the SDK/CDN-profile defaults).
     *
     * @param array<string, mixed> $values Raw config values
     *
     * @return array<string, mixed> The sanitized widget config
     */
    public static function buildConfig(array $values): array
    {
        $allowed = array_flip(self::WIDGET_CONFIG_KEYS);

        return array_filter(
            $values,
            static fn ($value, $key) => isset($allowed[$key]) && $value !== null,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Renders the two-tag product widget init HTML: the JSON config block followed by the product widget <script src>.
     *
     * The config is JSON-encoded with the same defensive flags used across the SDK init helpers so that any
     * admin-controlled string (product names in productCartDetails, selectors, etc.) cannot terminate the script tag,
     * escape the JSON string, or smuggle entity references. The script URL is attribute-escaped.
     *
     * @param array<string, mixed> $config Widget config values (pass through {@see buildConfig()} first, or a raw array
     *                                     that already matches the WidgetConfig contract)
     * @param string $url Absolute product widget script URL (see {@see getScriptUrl()})
     * @param SerializerInterface|null $serializer Optional JSON serializer; if null, a default Json serializer is used
     *
     * @return string The rendered HTML (config block + product widget script tag)
     */
    public static function renderScript(array $config, string $url, ?SerializerInterface $serializer = null): string
    {
        $serializer ??= new JsonSerializer();

        try {
            $json = $serializer->serialize(
                $config,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
            );
        } catch (RequestValidationError) {
            $json = '{}';
        }

        return '<script type="application/json" id="' . self::CONFIG_ELEMENT_ID . '">' . $json . '</script>' .
            '<script src="' . htmlspecialchars($url, ENT_QUOTES) . '"></script>';
    }
}
