<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Log
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Log;

/**
 * Checks if the service mode cookie is active.
 *
 * Used by logging facades to determine whether enhanced logging should be enabled.
 */
class CookieServiceModeChecker implements ServiceModeCheckerInterface
{
    private const COOKIE_NAME = 'COMFINO_SERVICE_SESSION';
    private const COOKIE_VALUE = 'ACTIVE';

    /**
     * Checks if the service mode is active via cookie.
     *
     * @return bool True if the service mode cookie is present and set to 'ACTIVE'
     */
    public function isServiceMode(): bool
    {
        return isset($_COOKIE[self::COOKIE_NAME]) && $_COOKIE[self::COOKIE_NAME] === self::COOKIE_VALUE;
    }
}
