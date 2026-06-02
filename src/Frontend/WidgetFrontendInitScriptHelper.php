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
use InvalidArgumentException;

/**
 * Helper class for rendering the Comfino widget initialization script for the widget-frontend facade.
 *
 * Targets widget-frontend.min.js and initializes the widget via ComfinoWidgetFrontend.init(). Use
 * WidgetSdkInitScriptHelper for new integrations that use the Comfino Web SDK (comfino-sdk.min.js).
 */
class WidgetFrontendInitScriptHelper
{
    public const WIDGET_INIT_PARAMS = [
        'WIDGET_KEY',
        'WIDGET_PRICE_SELECTOR',
        'WIDGET_TARGET_SELECTOR',
        'WIDGET_PRICE_OBSERVER_SELECTOR',
        'WIDGET_PRICE_OBSERVER_LEVEL',
        'WIDGET_TYPE',
        'OFFER_TYPES',
        'EMBED_METHOD',
        'SHOW_PROVIDER_LOGOS',
        'CUSTOM_BANNER_CSS_URL',
        'CUSTOM_CALCULATOR_CSS_URL',
    ];

    public const WIDGET_INIT_VARIABLES = [
        'WIDGET_SCRIPT_URL',
        'PRODUCT_ID',
        'PRODUCT_PRICE',
        'AVAILABLE_PRODUCT_TYPES',
        'PRODUCT_CART_DETAILS',
        'LANGUAGE',
        'CURRENCY',
        'SHOP_ENVIRONMENT',
    ];

    /**
     * Renders the Comfino widget initialization script.
     *
     * @param string $widgetInitCode The Comfino widget initialization JavaScript code
     * @param array<string, mixed> $widgetInitParams The Comfino widget initialization parameters
     * @param array<string, mixed> $widgetInitVariables The Comfino widget initialization variables
     * @param SerializerInterface|null $serializer Optional JSON serializer; if null, a default Json serializer is used
     *
     * @return string The rendered Comfino widget initialization script
     *
     * @throws InvalidArgumentException
     * @throws RequestValidationError
     */
    public static function renderWidgetInitScript(
        string $widgetInitCode,
        array $widgetInitParams,
        array $widgetInitVariables,
        ?SerializerInterface $serializer = null
    ): string {
        $serializer ??= new JsonSerializer();
        $widgetInitParamsAssocKeys = array_flip(self::WIDGET_INIT_PARAMS);
        $widgetInitVariablesAssocKeys = array_flip(self::WIDGET_INIT_VARIABLES);
        $widgetInitParamsCommon = array_intersect_key($widgetInitParamsAssocKeys, $widgetInitParams);
        $widgetInitVariablesCommon = array_intersect_key($widgetInitVariablesAssocKeys, $widgetInitVariables);

        if (count($widgetInitParamsCommon) !== count(self::WIDGET_INIT_PARAMS)) {
            throw new InvalidArgumentException('Invalid widget initialization parameters.');
        }

        if (count($widgetInitVariablesCommon) !== count(self::WIDGET_INIT_VARIABLES)) {
            throw new InvalidArgumentException('Invalid widget initialization variables.');
        }

        $serializeValue = static function ($varValue) use ($serializer): string {
            if ($varValue === null) {
                return 'null';
            }

            if (is_bool($varValue)) {
                return $varValue ? 'true' : 'false';
            }

            if (is_int($varValue) || is_float($varValue) || is_numeric($varValue)) {
                return (string) $varValue;
            }

            /* The same defensive flags are applied to arrays as to scalars: the helper output is embedded directly into
               a <script> tag in the page-side init script, so any string buried inside an array (e.g., product names in
               productCartDetails coming from admin-controlled catalog data) must be encoded so it cannot terminate the
               script tag, escape the JS string delimiter, or smuggle entity references. Without these flags, a product
               name like `</script><script>alert(1)</script>` would render verbatim into the page and break out of the
               script context. */
            return $serializer->serialize(
                $varValue,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
            );
        };

        return str_replace(
            array_merge(
                array_map(
                    static fn (string $widgetInitParamName): string => '{' . $widgetInitParamName . '}',
                    array_merge(self::WIDGET_INIT_PARAMS, array_keys($widgetInitVariables))
                ),
                ["'true'", "'false'", "'null'"]
            ),
            array_merge(
                array_map(
                    $serializeValue,
                    array_merge(
                        array_merge($widgetInitParamsAssocKeys, $widgetInitParams),
                        array_values($widgetInitVariables)
                    )
                ),
                ['true', 'false', 'null']
            ),
            $widgetInitCode
        );
    }

    /**
     * Checks if the provided widget initialization script requires an update.
     *
     * @param string $widgetInitCode The Comfino widget initialization JavaScript code
     *
     * @return bool True if the script requires an update, false otherwise
     */
    public static function initScriptRequiresUpdate(string $widgetInitCode): bool
    {
        return hash('sha256', $widgetInitCode) !== hash('sha256', self::getInitialWidgetCode());
    }

    /**
     * Returns the hash of the initial widget initialization script.
     *
     * @return string The hash of the initial widget initialization script
     */
    public static function getInitialWidgetCodeHash(): string
    {
        return hash('sha256', self::getInitialWidgetCode());
    }

    /**
     * Returns the initial widget initialization script.
     *
     * @return string The initial widget initialization script
     */
    public static function getInitialWidgetCode(): string
    {
        return include 'WidgetFrontendInitScript.php';
    }
}
