<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Settings
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Settings;

/**
 * Provides the current shop language for API communication.
 *
 * Implementations read the active locale from the host platform and return a normalized ISO 639-1 language code.
 */
interface LanguageProviderInterface
{
    /**
     * Returns the current shop language as an ISO 639-1 code (e.g. "pl", "en").
     */
    public function getLanguage(): string;
}
