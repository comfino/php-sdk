<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

/**
 * Tracks whether an API-initiated order status change is currently being applied.
 *
 * Platforms that react to order persistence through event hooks (e.g., Magento observers, PrestaShop hooks) can use
 * this context to suppress reflexive outbound calls back to the Comfino API - for instance, to avoid sending a
 * cancellation request for an order that the Comfino API itself just reported as canceled.
 *
 * The context is entered automatically by {@see AbstractStatusAdapter::setStatus()} for the duration of the
 * platform-specific apply step, so integrations only need to query {@see isActive()} from their event hook.
 *
 * A depth counter (rather than a plain flag) keeps the context correct if status application ever nests.
 */
final class StatusApplicationContext
{
    private static int $depth = 0;

    /**
     * Runs the given callback with the context marked active, guaranteeing the context is exited even on exception.
     *
     * @template T
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function run(callable $callback)
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    /**
     * Returns true while an API-initiated status change is being applied.
     */
    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
