<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Queue;

/**
 * Minimal randomness abstraction so jittered delays are deterministic under test.
 */
interface JitterInterface
{
    /**
     * Returns a random integer in [0, $maxInclusive]. A non-positive $maxInclusive always returns 0.
     */
    public function random(int $maxInclusive): int;
}
