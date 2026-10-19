<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Clock
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Clock;

/**
 * Default clock backed by the system time.
 */
final class SystemClock implements ClockInterface
{
    /**
     * @param array<string, mixed> $array
     */
    public static function __set_state(array $array): self
    {
        return new self();
    }

    public function now(): int
    {
        return time();
    }
}
