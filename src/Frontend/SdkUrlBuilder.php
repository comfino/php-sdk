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

use Comfino\Api\Validation\UrlValidator;

/**
 * Cross-platform builder for the Comfino web SDK (comfino-sdk.min.js) and backend API URLs.
 *
 * Centralizes the CDN hosts, the script path shape (/sdk/v<version>/comfino-sdk.min.js) and the dev-environment URL
 * overrides so that every shop plugin produces identical URLs instead of re-implementing the logic by hand.
 *
 * The web SDK is a single, ESM-only bundle (`comfino-sdk.min.js`) served from the primary `sdk.comfino.pl` host
 * under the `/sdk/v<n>/` path. It is always loaded via `<script type="module">`.
 *
 * Dev-environment overriding is hardened against spoofing in two layers:
 *  1. Overrides are honored only when the server-set COMFINO_DEV_ENV environment variable equals 'TRUE'. A production
 *     shop never sets it, so the standard URLs cannot be replaced there.
 *  2. Even under COMFINO_DEV_ENV, an override URL is accepted only when it passes the shared {@see UrlValidator}
 *     allow-list (Comfino domains over HTTPS, private/loopback IPs, single-label hostnames) — so a tampered env var
 *     cannot point the SDK/API at an attacker-controlled host.
 */
final class SdkUrlBuilder
{
    public const SDK_SCRIPT_PRODUCTION_HOST = 'https://sdk.comfino.pl';
    public const SDK_SCRIPT_SANDBOX_HOST = 'https://sdk.craty.pl';
    public const DEFAULT_SDK_VERSION = 1;

    private const SDK_SCRIPT_FILE = 'comfino-sdk.min.js';
    private const DEFAULT_LOGO_PATH = '/images/comfino/comfino_logo.svg';

    /**
     * Builds the Comfino web SDK (ESM) script URL for the given environment and SDK version. The bundle is always an
     * ES module, loaded via <script type="module">.
     *
     * @param bool $sandboxMode Whether the shop runs in sandbox mode
     * @param bool $devOverridesEnabled The plugin's per-shop dev-override opt-in (e.g. Magento COMFINO_DEV_ENV_VARS)
     * @param int $version SDK major version number (e.g. 1 → '/v1/')
     *
     * @return string The absolute SDK script URL
     */
    public static function getSdkScriptUrl(
        bool $sandboxMode,
        bool $devOverridesEnabled = false,
        int $version = self::DEFAULT_SDK_VERSION
    ): string {
        return self::resolveCdnHost(
            $devOverridesEnabled,
            $sandboxMode ? self::SDK_SCRIPT_SANDBOX_HOST : self::SDK_SCRIPT_PRODUCTION_HOST
        ) . '/sdk/v' . $version . '/' . self::SDK_SCRIPT_FILE;
    }

    /**
     * Builds the Comfino product-page widget script URL (the classic-IIFE glue that reads the
     * `#comfino-widget-config` JSON block and calls sdk.bootstrapWidget()). The product-page sibling of
     * {@see FrontendHelper::getCheckoutScriptUrl()}. Defaults to the GENERIC script; pass a platform for a
     * Comfino-maintained plugin's dedicated per-platform script.
     *
     * @param bool $sandboxMode Whether the shop runs in sandbox mode
     * @param string|null $platform Platform id for the per-platform script (e.g. 'prestashop'); null selects generic
     * @param bool $devOverridesEnabled The plugin's per-shop dev-override opt-in
     * @param int $version Product widget script major version number (e.g. 1 → '/product/v1/')
     *
     * @return string The absolute product widget script URL
     */
    public static function getProductWidgetScriptUrl(
        bool $sandboxMode,
        ?string $platform = null,
        bool $devOverridesEnabled = false,
        int $version = self::DEFAULT_SDK_VERSION
    ): string {
        return self::rehost(
            ProductWidgetScriptHelper::getScriptUrl($sandboxMode, $platform, $version),
            $devOverridesEnabled
        );
    }

    /**
     * Builds the CDN URL of the default Comfino brand logo (the payment-tile placeholder shown before the SDK renderer
     * swaps it for the auth-gated API logo).
     *
     * @param bool $sandboxMode Whether the shop runs in sandbox mode
     * @param bool $devOverridesEnabled The plugin's per-shop dev-override opt-in
     *
     * @return string The absolute default logo URL
     */
    public static function getDefaultLogoUrl(bool $sandboxMode, bool $devOverridesEnabled = false): string
    {
        return self::resolveCdnHost(
            $devOverridesEnabled,
            $sandboxMode ? self::SDK_SCRIPT_SANDBOX_HOST : self::SDK_SCRIPT_PRODUCTION_HOST
        ) . self::DEFAULT_LOGO_PATH;
    }

    /**
     * Resolves the dev-environment backend API host override, when configured and allowed.
     *
     * The standard production/sandbox API base URLs live in the API client itself; this only returns a non-null value
     * when a (safe, allowlisted) dev override is in effect.
     *
     * @param bool $devOverridesEnabled The plugin's per-shop dev-override opt-in
     *
     * @return string|null The API host override, or null when none applies
     */
    public static function getApiHostOverride(bool $devOverridesEnabled = false): ?string
    {
        return self::devEnvActive($devOverridesEnabled) ? self::safeOverride('COMFINO_DEV_API_HOST') : null;
    }

    /**
     * Returns the CDN host to build an SDK/logo URL against: the dev-override base URL when a safe override is in
     * effect, otherwise the given standard (production/sandbox) host. Any trailing slash on the override is stripped so
     * the caller can append the path segment unconditionally.
     *
     * @param bool $devOverridesEnabled The plugin's per-shop dev-override opt-in
     * @param string $standardHost The production/sandbox host to fall back to when no override applies
     *
     * @return string The resolved host (scheme + host [+ port]), without a trailing slash
     */
    private static function resolveCdnHost(bool $devOverridesEnabled, string $standardHost): string
    {
        if (
            self::devEnvActive($devOverridesEnabled) &&
            ($baseUrl = self::safeOverride('COMFINO_DEV_SDK_CDN_BASE_URL')) !== null
        ) {
            return rtrim($baseUrl, '/');
        }

        return $standardHost;
    }

    /**
     * Re-points an already-built standard URL at the dev-override CDN base while preserving its path when a safe
     * override is in effect. Used for URLs whose path is computed elsewhere (e.g., the product widget script), so only
     * the scheme/host/port are swapped. Returns the URL unchanged when no override applies.
     *
     * @param string $url The fully-built production/sandbox URL to re-host
     * @param bool $devOverridesEnabled The plugin's per-shop dev-override opt-in
     *
     * @return string The re-hosted URL, or the original URL when no override applies
     */
    private static function rehost(string $url, bool $devOverridesEnabled): string
    {
        if (
            !self::devEnvActive($devOverridesEnabled) ||
            ($baseUrl = self::safeOverride('COMFINO_DEV_SDK_CDN_BASE_URL')) === null
        ) {
            return $url;
        }

        return rtrim($baseUrl, '/') . parse_url($url, PHP_URL_PATH);
    }

    /**
     * Hard production guard: dev overrides require the server-set COMFINO_DEV_ENV environment variable in addition to
     * the plugin's per-shop opt-in.
     *
     * @param bool $devOverridesEnabled The plugin's per-shop dev-override opt-in flag
     *
     * @return bool True only when both the server env var and the per-shop flag are set
     */
    private static function devEnvActive(bool $devOverridesEnabled): bool
    {
        return $devOverridesEnabled && getenv('COMFINO_DEV_ENV') === 'TRUE';
    }

    /**
     * Reads an environment variable and returns its value only when it parses as an allowlisted Comfino/dev URL, so a
     * tampered env var cannot redirect the SDK/API to a spoofed host.
     *
     * @param string $envVar Name of the environment variable to read
     *
     * @return string|null The validated URL value, or null when absent or not allow-listed
     */
    private static function safeOverride(string $envVar): ?string
    {
        $value = getenv($envVar);

        if ($value === false || $value === '') {
            return null;
        }

        return UrlValidator::isAllowedUrl($value) ? $value : null;
    }
}
