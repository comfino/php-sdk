<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Platform
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Platform;

/**
 * The one place that knows what a Comfino User-Agent string looks like.
 *
 * The format is a support-desk artifact: given a User-Agent from an API log, a human has to be able to say which
 * integration, which version of it, and which merchant produced the call. It reads:
 *
 *     <CODE> Comfino [<pluginVersion>], <name> [<platformVersion>], PHP [<phpVersion>], <domain>
 *
 * For example
 *
 *     MG Comfino [4.0.0], Magento [2.4.7], PHP [8.2.10], myshop.pl
 *     IDO Comfino [1.0.0], connector [1.0.0], PHP [8.3.1], connector.example.com
 *
 * A value an out-of-process connector cannot know is rendered as {@see UNKNOWN_VALUE} rather than omitted, so the
 * segment count stays fixed and the string parses the same way whoever produced it. That matters more than
 * terseness: a missing segment reads as a truncated string, whereas an explicit "unknown" reads as a fact about the
 * deployment.
 *
 * This builder exists because every integration running outside the shop was otherwise going to re-derive the
 * format by hand from the `sprintf()` inside `ApiClientFactory` — and the first one that did drifted from it
 * immediately.
 */
final class UserAgentBuilder
{
    /** Rendered in place of a value the integration legitimately cannot determine. */
    public const UNKNOWN_VALUE = 'unknown';

    /**
     * Builds the User-Agent string for the given integration metadata.
     *
     * @param ConnectorInfoInterface $info Integration metadata; a {@see PlatformInfoInterface} implementation for an
     *                                     in-shop plugin, or {@see HostedConnectorInfo} for a connector running
     *                                     outside the shop
     *
     * @return string The User-Agent header value
     */
    public static function build(ConnectorInfoInterface $info): string
    {
        return sprintf(
            '%s Comfino [%s], %s [%s], PHP [%s], %s',
            $info->getCode(),
            $info->getPluginVersion(),
            $info->getName(),
            self::orUnknown($info->getVersion()),
            self::orUnknown($info->getPhpVersion()),
            self::orUnknown($info->getDomain())
        );
    }

    /**
     * Renders a nullable metadata value, substituting {@see UNKNOWN_VALUE} for null and for an empty string.
     *
     * An empty string is treated as unknown deliberately: platforms that read these values from configuration
     * routinely resolve an unset option to '' rather than to null, and "PHP []" in an API log is worse than useless.
     *
     * @param string|null $value The raw metadata value
     *
     * @return string The value, or {@see UNKNOWN_VALUE}
     */
    private static function orUnknown(?string $value): string
    {
        return ($value === null || $value === '') ? self::UNKNOWN_VALUE : $value;
    }
}
