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
 * Accepts only absolute https:// URLs, optionally restricted to hosts ending in one of the given suffixes (e.g. the
 * custom paywall stylesheet). An empty value is accepted unless $allowEmpty is false.
 */
final class HttpsUrlConstraint implements ConfigValueConstraintInterface
{
    /**
     * @param string[] $allowedHostSuffixes Host suffixes such as "comfino.pl" (matches the host and its subdomains); empty allows any host
     * @param bool $allowEmpty Whether an empty value (clearing the option) is accepted
     */
    public function __construct(private readonly array $allowedHostSuffixes = [], private readonly bool $allowEmpty = true)
    {
    }

    public function apply(string $optionName, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            if ($this->allowEmpty) {
                return $value;
            }

            throw new InvalidConfigValue("$optionName must not be empty.");
        }

        if (!is_string($value)) {
            throw new InvalidConfigValue("$optionName must be a URL string.");
        }

        $value = trim($value);
        $parts = parse_url($value);

        if ($parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || strtolower($parts['scheme'] ?? '') !== 'https') {
            throw new InvalidConfigValue("$optionName must be an absolute https:// URL without credentials.");
        }

        if ($this->allowedHostSuffixes !== [] && !$this->hostAllowed(strtolower($parts['host']))) {
            throw new InvalidConfigValue("$optionName points to a host that is not allowed.");
        }

        return $value;
    }

    private function hostAllowed(string $host): bool
    {
        foreach ($this->allowedHostSuffixes as $suffix) {
            $suffix = strtolower(ltrim($suffix, '.'));

            if ($host === $suffix || str_ends_with($host, ".$suffix")) {
                return true;
            }
        }

        return false;
    }
}
