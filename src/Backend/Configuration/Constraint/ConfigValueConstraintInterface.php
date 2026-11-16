<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Configuration\Constraint
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Configuration\Constraint;

/**
 * Validates or normalizes one configuration value before it is stored.
 *
 * Registered per option with {@see \Comfino\Backend\Configuration\ConfigurationManager::setValueConstraints()}. It runs on
 * every write, including remote writes through the configuration webhook, which bypass the platform's own admin-form validation.
 */
interface ConfigValueConstraintInterface
{
    /**
     * @param string $optionName Option being written
     * @param mixed $value Value requested by the writer
     *
     * @return mixed The value to store, possibly normalized or clamped
     *
     * @throws InvalidConfigValue To reject the value; the option keeps its current value
     */
    public function apply(string $optionName, mixed $value): mixed;
}
