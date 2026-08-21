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

use Throwable;

/**
 * Helper class for frontend routines.
 */
final class FrontendHelper
{
    /** Path, relative to the Comfino CDN origin, of the shared checkout payment-method-item stylesheet. */
    private const CHECKOUT_LOADER_CSS_PATH = '/css/comfino-checkout.css';

    /** Path template, relative to the Comfino CDN origin, of a platform's checkout glue bundle (%s = platform slug). */
    private const CHECKOUT_SCRIPT_PATH_TEMPLATE = '/checkout/v1/comfino-%s.min.js';

    /** Path template, relative to the Comfino CDN origin, of a platform's product-page widget bundle (%s = platform slug). */
    private const PRODUCT_WIDGET_SCRIPT_PATH_TEMPLATE = '/product/v1/comfino-%s-widget.min.js';

    /**
     * Resolves the URL of the shared, CDN-served checkout stylesheet (comfino-checkout.css) — the cross-platform
     * payment-method-item visual layer, e.g., the loading-placeholder spinner. The URL is derived from the Comfino web
     * SDK script URL's origin, so it always points at the same CDN as the SDK (production / sandbox / dev) without each
     * shop module hardcoding or re-deriving the host. Returns null when the SDK script URL is empty or malformed.
     *
     * @param string $sdkScriptUrl The Comfino web SDK script URL (as returned by the shop's config manager)
     *
     * @return string|null The absolute checkout stylesheet URL, or null if it cannot be derived
     */
    public static function getCheckoutLoaderStylesheetUrl(string $sdkScriptUrl): ?string
    {
        $origin = self::deriveCdnOrigin($sdkScriptUrl);

        return $origin === null ? null : $origin . self::CHECKOUT_LOADER_CSS_PATH;
    }

    /**
     * Resolves the URL of a platform's CDN-served checkout glue bundle (e.g., comfino-magento-hyva.min.js) — the
     * shop-side IIFE registrar/bootstrapper that loads the web SDK and wires up the paywall. Like
     * {@see getCheckoutLoaderStylesheetUrl()}, the URL is derived from the SDK script URL's origin so it always points
     * at the same CDN as the SDK without the shop module hardcoding the host. Returns null when the SDK script URL is
     * empty/malformed or the platform slug is not a bare lowercase/digit/hyphen token (guards the path segment).
     *
     * @param string $sdkScriptUrl The Comfino web SDK script URL (as returned by the shop's config manager)
     * @param string $platform The platform slug used in the bundle filename (e.g. 'magento-hyva', 'prestashop')
     *
     * @return string|null The absolute checkout glue script URL, or null if it cannot be derived
     */
    public static function getCheckoutScriptUrl(string $sdkScriptUrl, string $platform): ?string
    {
        if (preg_match('/^[a-z0-9-]+$/', $platform) !== 1) {
            return null;
        }

        $origin = self::deriveCdnOrigin($sdkScriptUrl);

        return $origin === null ? null : $origin . sprintf(self::CHECKOUT_SCRIPT_PATH_TEMPLATE, $platform);
    }

    /**
     * Resolves the URL of a platform's CDN-served product-page widget bundle (e.g., comfino-magento-hyva-widget.min.js)
     * — the shop-side IIFE that reads the `#comfino-widget-config` block, loads the web SDK, and calls
     * sdk.bootstrapWidget(). The product-page sibling of {@see getCheckoutScriptUrl()}; the URL is derived from the SDK
     * script URL's origin so it always points at the same CDN as the SDK. Returns null when the SDK script URL is
     * empty/malformed or the platform slug is not a bare lowercase/digit/hyphen token.
     *
     * @param string $sdkScriptUrl The Comfino web SDK script URL (as returned by the shop's config manager)
     * @param string $platform The platform slug used in the bundle filename (e.g. 'magento-hyva', 'prestashop')
     *
     * @return string|null The absolute product widget script URL, or null if it cannot be derived
     */
    public static function getProductWidgetScriptUrl(string $sdkScriptUrl, string $platform): ?string
    {
        if (preg_match('/^[a-z0-9-]+$/', $platform) !== 1) {
            return null;
        }

        $origin = self::deriveCdnOrigin($sdkScriptUrl);

        return $origin === null ? null : $origin . sprintf(self::PRODUCT_WIDGET_SCRIPT_PATH_TEMPLATE, $platform);
    }

    /**
     * Generates a logo authentication hash for the Comfino payment gateway.
     *
     * @param string $platformCode The shop platform code
     * @param string $platformVersion The shop platform version
     * @param string $pluginVersion The shop plugin version
     * @param int $buildTimestamp The shop plugin build timestamp
     *
     * @return string The logo authentication hash
     */
    public static function getLogoAuthHash(
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        int $buildTimestamp
    ): string {
        return rawurlencode(
            base64_encode(self::getLogoAuthKey($platformCode, $platformVersion, $pluginVersion, $buildTimestamp))
        );
    }

    /**
     * Generates a paywall logo authentication hash for the Comfino payment gateway.
     *
     * @param string $platformCode The shop platform code
     * @param string $platformVersion The shop platform version
     * @param string $pluginVersion The shop plugin version
     * @param string $apiKey The shop plugin API key
     * @param string $widgetKey The shop widget key
     * @param int $buildTimestamp The shop plugin build timestamp
     *
     * @return string The paywall logo authentication hash
     */
    public static function getPaywallLogoAuthHash(
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        string $apiKey,
        string $widgetKey,
        int $buildTimestamp
    ): string {
        return rawurlencode(self::getPaywallLogoAuthHashRaw(
            $platformCode,
            $platformVersion,
            $pluginVersion,
            $apiKey,
            $widgetKey,
            $buildTimestamp
        ));
    }

    /**
     * Same authentication payload as {@see getPaywallLogoAuthHash()} but without URL-encoding — returns the bare base64
     * string. Use this when the consumer percent-encodes the value itself at the URL boundary (e.g., the JS SDK's
     * URLSearchParams.set('auth', hash)), so we don't double-encode.
     */
    public static function getPaywallLogoAuthHashRaw(
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        string $apiKey,
        string $widgetKey,
        int $buildTimestamp
    ): string {
        $authKey = self::getLogoAuthKey($platformCode, $platformVersion, $pluginVersion, $buildTimestamp) . $widgetKey;
        $authKey .= hash_hmac('sha3-256', $authKey, $apiKey, true);

        return base64_encode($authKey);
    }

    /**
     * Generates a logo authentication key for the Comfino payment gateway.
     *
     * @param string $platformCode The shop platform code
     * @param string $platformVersion The shop platform version
     * @param string $pluginVersion The shop plugin version
     * @param int $buildTimestamp The shop plugin build timestamp
     *
     * @return string The logo authentication key
     */
    public static function getLogoAuthKey(
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        int $buildTimestamp
    ): string {
        $packedPlatformVersion = pack('c*', ...array_map('intval', explode('.', $platformVersion)));
        $packedPluginVersion = pack('c*', ...array_map('intval', explode('.', $pluginVersion)));
        $platformVersionLength = pack('c', strlen($packedPlatformVersion));
        $pluginVersionLength = pack('c', strlen($packedPluginVersion));
        $packedBuildTimestamp = pack('J', $buildTimestamp);

        $authKeyParts = [
            $platformCode,
            $platformVersionLength,
            $pluginVersionLength,
            $packedPlatformVersion,
            $packedPluginVersion,
            $packedBuildTimestamp,
        ];

        return implode($authKeyParts);
    }

    /**
     * Renders the Comfino payment gateway logo.
     *
     * @param string $apiBaseUrl The Comfino payment gateway API base URL
     * @param string $platformCode The shop platform code
     * @param string $platformVersion The shop platform version
     * @param string $pluginVersion The shop plugin version
     * @param int $buildTimestamp The shop plugin build timestamp
     * @param string $style The logo image style
     * @param string $alt The logo image alt text
     *
     * @return string The rendered Comfino payment gateway logo
     */
    public static function renderAdminLogo(
        string $apiBaseUrl,
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        int $buildTimestamp,
        string $style = '',
        string $alt = ''
    ): string {
        return self::renderLogoImg(
            $apiBaseUrl,
            'v1/get-logo-url',
            self::getLogoAuthHash($platformCode, $platformVersion, $pluginVersion, $buildTimestamp),
            $style,
            $alt
        );
    }

    /**
     * Renders the Comfino payment gateway paywall logo.
     *
     * @param string $apiBaseUrl The Comfino payment gateway API base URL
     * @param string $apiKey The shop plugin API key
     * @param string $widgetKey The shop widget key
     * @param string $platformCode The shop platform code
     * @param string $platformVersion The shop platform version
     * @param string $pluginVersion The shop plugin version
     * @param int $buildTimestamp The shop plugin build timestamp
     * @param string $style The logo image style
     * @param string $alt The logo image alt text
     *
     * @return string The HTML image tag for the Comfino paywall logo
     */
    public static function renderPaywallLogo(
        string $apiBaseUrl,
        string $apiKey,
        string $widgetKey,
        string $platformCode,
        string $platformVersion,
        string $pluginVersion,
        int $buildTimestamp,
        string $style = '',
        string $alt = ''
    ): string {
        return self::renderLogoImg(
            $apiBaseUrl,
            'v1/get-paywall-logo',
            self::getPaywallLogoAuthHash(
                $platformCode,
                $platformVersion,
                $pluginVersion,
                $apiKey,
                $widgetKey,
                $buildTimestamp
            ),
            $style,
            $alt
        );
    }

    /**
     * Prepares error details for logging or debugging.
     *
     * @param string $userErrorMessage The user-friendly error message
     * @param int $statusCode The HTTP status code
     * @param bool $isDebugMode Indicates if debug mode is enabled
     * @param Throwable $exception The exception object
     * @param bool $isTimeout Indicates if the error is due to a timeout
     * @param int $connectAttemptIdx The index of the connection attempt
     * @param int $connectionTimeout The connection timeout duration
     * @param int $transferTimeout The transfer timeout duration
     * @param string|null $url The API request URL
     * @param string|null $requestBody The request body content
     * @param string|null $responseBody The response body content
     *
     * @return array<string, mixed> The prepared error details
     */
    public static function prepareErrorDetails(
        string $userErrorMessage,
        int $statusCode,
        bool $isDebugMode,
        Throwable $exception,
        bool $isTimeout,
        int $connectAttemptIdx,
        int $connectionTimeout,
        int $transferTimeout,
        ?string $url = null,
        ?string $requestBody = null,
        ?string $responseBody = null
    ): array {
        if ($isDebugMode) {
            return array_filter([
                'userErrorMessage' => $userErrorMessage,
                'statusCode' => $statusCode,
                'exceptionClass' => get_class($exception),
                'errorMessage' => $exception->getMessage(),
                'errorCode' => $exception->getCode(),
                'errorFile' => $exception->getFile(),
                'errorLine' => $exception->getLine(),
                'errorTrace' => $exception->getTraceAsString(),
                'url' => $url,
                'requestBody' => $requestBody,
                'responseBody' => $responseBody,
                'connectAttemptIdx' => $connectAttemptIdx,
                'connectionTimeout' => $connectionTimeout,
                'transferTimeout' => $transferTimeout,
                'isTimeout' => $isTimeout,
                'isDebugMode' => true,
            ]);
        }

        return [
            'userErrorMessage' => $userErrorMessage,
            'statusCode' => $statusCode,
            'errorCode' => $exception->getCode(),
            'connectAttemptIdx' => $connectAttemptIdx,
            'connectionTimeout' => $connectionTimeout,
            'transferTimeout' => $transferTimeout,
            'isTimeout' => $isTimeout,
            'isDebugMode' => false
        ];
    }

    /**
     * Extracts the scheme://host[:port] origin from the Comfino web SDK script URL, so CDN-served sibling assets
     * (stylesheet, checkout glue) can be addressed on the same host as the SDK. Returns null when the URL is empty or
     * lacks a scheme/host.
     *
     * @param string $sdkScriptUrl The Comfino web SDK script URL
     *
     * @return string|null The absolute origin (no trailing slash), or null if it cannot be derived
     */
    private static function deriveCdnOrigin(string $sdkScriptUrl): ?string
    {
        if ($sdkScriptUrl === '') {
            return null;
        }

        $parts = parse_url($sdkScriptUrl);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /**
     * Renders the Comfino payment gateway logo image.
     *
     * @param string $apiHost The API host URL
     * @param string $apiEndpoint The API endpoint path
     * @param string $auth The authentication token
     * @param string $style The CSS style for the image
     * @param string $alt The alternative text for the image
     *
     * @return string The HTML image tag for the Comfino logo
     */
    private static function renderLogoImg(
        string $apiHost,
        string $apiEndpoint,
        string $auth,
        string $style,
        string $alt
    ): string {
        $src = htmlspecialchars($apiHost, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '/' . $apiEndpoint . '?auth=' .
            htmlspecialchars($auth, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $img = '<img src="' . $src . '"';

        if (!empty($style)) {
            $img .= ' style="' . htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        if (!empty($alt)) {
            $img .= ' alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        $img .= '>';

        return $img;
    }
}
