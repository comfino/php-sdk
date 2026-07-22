<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Queue;

use Comfino\Backend\Queue\JitterInterface;

/**
 * Deterministic jitter source for tests; always returns the configured value regardless of $maxInclusive.
 */
final class FixedJitter implements JitterInterface
{
    public function __construct(private readonly int $value = 0)
    {
    }

    public function random(int $maxInclusive): int
    {
        return $this->value;
    }
}
