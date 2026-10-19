<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Webhook\Endpoint
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Webhook\Endpoint;

use Comfino\Shop\Order\StatusAdapterInterface;

/**
 * An adapter that records what it was told to apply.
 */
final class RecordingAdapter implements StatusAdapterInterface
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $applied = [];

    public function setStatus(string $orderId, string $status): void
    {
        $this->applied[] = [$orderId, $status];
    }
}
