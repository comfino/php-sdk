<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Queue;

use RuntimeException;

/**
 * The error a request is dead-lettered with when it outlived the queue's maximum age.
 */
final class RequestExpired extends RuntimeException
{
    public static function olderThan(int $maxAgeSeconds): self
    {
        return new self("Queued request is older than the maximum age of $maxAgeSeconds seconds.");
    }
}
