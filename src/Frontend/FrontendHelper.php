<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Frontend
 * @author Artur Kozubski <akozubski@comperia.pl>
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
        $authKey = self::getLogoAuthKey($platformCode, $platformVersion, $pluginVersion, $buildTimestamp) . $widgetKey;
        $authKey .= hash_hmac('sha3-256', $authKey, $apiKey, true);

        return rawurlencode(base64_encode($authKey));
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
