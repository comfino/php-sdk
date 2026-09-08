<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order;

use Comfino\Shop\Order\StatusAdapterInterface;

/**
 * A bare {@see StatusAdapterInterface} that records what it was told to apply.
 *
 * Distinct from {@see RecordingStatusAdapter}, which extends `AbstractStatusAdapter` and therefore exercises that
 * class's ignore/forbid/map pipeline. The tests about *which adapter instance* a manager holds want neither the
 * pipeline nor its configuration in the way.
 */
final class CountingStatusAdapter implements StatusAdapterInterface
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $applied = [];

    public function setStatus(string $orderId, string $status): void
    {
        $this->applied[] = [$orderId, $status];
    }
}
