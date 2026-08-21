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
 * Default jitter source backed by the interpreter's PRNG.
 */
final class SystemJitter implements JitterInterface
{
    /**
     * @param array<string, mixed> $array
     */
    public static function __set_state(array $array): self
    {
        return new self();
    }

    public function random(int $maxInclusive): int
    {
        return $maxInclusive > 0 ? random_int(0, $maxInclusive) : 0;
    }
}
