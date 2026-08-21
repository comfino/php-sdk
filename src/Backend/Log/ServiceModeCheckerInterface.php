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
 * Interface for checking if the application is running in service mode.
 */
interface ServiceModeCheckerInterface
{
    /**
     * Checks if the application is running in service mode.
     *
     * @return bool True if in service mode, false otherwise
     */
    public function isServiceMode(): bool;
}
