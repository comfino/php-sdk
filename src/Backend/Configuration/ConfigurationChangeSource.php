<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Configuration
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Configuration;

/**
 * Where a configuration change came from, for {@see ConfigurationChangeObserverInterface}.
 */
enum ConfigurationChangeSource: string
{
    /** Written through the signed configuration webhook ({@see ConfigurationManager::updateConfigurationOptions()}). */
    case RemoteEndpoint = 'remote_endpoint';

    /** Written by the host itself (admin form, setup, cron). */
    case Programmatic = 'programmatic';
}
