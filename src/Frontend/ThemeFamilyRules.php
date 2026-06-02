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

/**
 * Registry of named predicates that map a theme inheritance chain to a normalized family name.
 *
 * Platforms register their detection logic via {@see register()} (e.g. in a bootstrap or DI configuration).
 * {@see resolveFamily()} then iterates the registered rules in insertion order and returns the first matching family,
 * falling back to 'custom' if none match.
 */
final class ThemeFamilyRules
{
    /** @var array<string, callable(string[]): bool> */
    private array $rules = [];

    /**
     * Registers a theme-family detection predicate.
     *
     * @param string $family Normalized family name (e.g. "hyva", "luma", "blank")
     * @param callable(string[]): bool $predicate Called with the lowercased theme chain array; returns true if the
     *                                            chain belongs to this family
     */
    public function register(string $family, callable $predicate): void
    {
        $this->rules[$family] = $predicate;
    }

    /**
     * Resolves the theme family for the given inheritance chain.
     *
     * Each element of {@see $themeChain} is lowercased before predicates are evaluated. Rules are tested in
     * registration order; the first match wins.
     *
     * @param string[] $themeChain Theme codes from active theme to root (e.g. ["Hyva/default", "Magento/blank"])
     *
     * @return string Matched family name, or 'custom' if no rule matched
     */
    public function resolveFamily(array $themeChain): string
    {
        $lowercasedChain = array_map('strtolower', $themeChain);

        foreach ($this->rules as $family => $predicate) {
            if ($predicate($lowercasedChain)) {
                return $family;
            }
        }

        return 'custom';
    }
}
