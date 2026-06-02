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
 * Maps a normalized theme family name to a set of frontend framework capability hints.
 *
 * Capability hints are sent to the Comfino backend only (not to the browser) and are used to build the selector-profile
 * knowledge base and CSS injection strategy for each platform.
 */
final class CapabilityResolver
{
    /**
     * Returns a framework-capability hint array for the given theme family.
     *
     * Keys: knockout, alpine, tailwind, requirejs, jquery — each is a boolean.
     *
     * @param string $family Normalized theme family (e.g. "hyva", "luma", "blank", "classic", "storefront", "custom")
     *
     * @return array<string, bool>
     */
    public static function fromThemeFamily(string $family): array
    {
        return match ($family) {
            'hyva' => [
                'knockout' => false,
                'alpine'   => true,
                'tailwind' => true,
                'requirejs' => false,
                'jquery'   => false,
            ],
            'luma', 'blank' => [
                'knockout' => true,
                'alpine'   => false,
                'tailwind' => false,
                'requirejs' => true,
                'jquery'   => true,
            ],
            'classic', 'storefront' => [
                'knockout' => false,
                'alpine'   => false,
                'tailwind' => false,
                'requirejs' => false,
                'jquery'   => true,
            ],
            default => [
                'knockout' => false,
                'alpine'   => false,
                'tailwind' => false,
                'requirejs' => false,
                'jquery'   => false,
            ],
        };
    }
}
