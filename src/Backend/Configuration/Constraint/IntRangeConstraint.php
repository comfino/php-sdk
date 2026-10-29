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
 * Keeps an integer option (timeouts, attempts, TTLs) within [$min, $max].
 *
 * Clamps by default: a remote write should degrade to a safe value rather than fail. With $clamp off, an
 * out-of-range value is rejected. Non-numeric values are always rejected.
 */
final class IntRangeConstraint implements ConfigValueConstraintInterface
{
    public function __construct(private readonly int $min, private readonly int $max, private readonly bool $clamp = true)
    {
        if ($min > $max) {
            throw new \InvalidArgumentException('Minimum must not exceed maximum.');
        }
    }

    public function apply(string $optionName, mixed $value): mixed
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1)) {
            $intValue = (int) $value;
        } else {
            throw new InvalidConfigValue("$optionName must be an integer.");
        }

        if ($intValue >= $this->min && $intValue <= $this->max) {
            return $intValue;
        }

        if (!$this->clamp) {
            throw new InvalidConfigValue("$optionName must be between {$this->min} and {$this->max}.");
        }

        return max($this->min, min($this->max, $intValue));
    }
}
