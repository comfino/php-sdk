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
 * Accepts only one of a fixed set of values (e.g. order status codes). For an array value (a list option or a status
 * map), every element must be allowed; with $checkKeys the keys are checked instead of the values.
 */
final class AllowedValuesConstraint implements ConfigValueConstraintInterface
{
    /**
     * @param array<int, string|int> $allowedValues Allowed values, compared as strings
     * @param bool $allowEmpty Whether null/empty (clearing the option) is accepted
     * @param bool $checkKeys For array values, check the keys rather than the values
     */
    public function __construct(
        private readonly array $allowedValues,
        private readonly bool $allowEmpty = true,
        private readonly bool $checkKeys = false
    ) {
    }

    public function apply(string $optionName, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            if ($this->allowEmpty) {
                return $value;
            }

            throw new InvalidConfigValue("$optionName must not be empty.");
        }

        $allowed = array_map('strval', $this->allowedValues);
        $candidates = is_array($value) ? ($this->checkKeys ? array_keys($value) : array_values($value)) : [$value];

        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate) || !in_array((string) $candidate, $allowed, true)) {
                throw new InvalidConfigValue("$optionName contains a value that is not allowed.");
            }
        }

        return $value;
    }
}
