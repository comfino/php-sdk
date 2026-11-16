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

use Comfino\Shop\Order\AbstractStatusAdapter;
use Comfino\Shop\Order\StatusApplicationContext;

/**
 * Concrete {@see AbstractStatusAdapter} for tests that records every applyStatus() invocation.
 */
final class RecordingStatusAdapter extends AbstractStatusAdapter
{
    /**
     * @var list<array{
     *     orderId: string,
     *     platformStatusCode: string,
     *     comfinoStatus: string,
     *     contextActive: bool,
     *     scopeActive: bool
     * }>
     */
    public array $applied = [];

    protected function applyStatus(string $orderId, string $platformStatusCode, string $comfinoStatus): void
    {
        $this->applied[] = [
            'orderId' => $orderId,
            'platformStatusCode' => $platformStatusCode,
            'comfinoStatus' => $comfinoStatus,
            'contextActive' => StatusApplicationContext::isActive(),
            // The precise, per-tenant answer, which is the one a multi-tenant host's event hooks must ask for.
            'scopeActive' => StatusApplicationContext::isActive($this->scope),
        ];
    }
}
