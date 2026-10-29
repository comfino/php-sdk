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
use Psr\Log\LoggerInterface;

/**
 * Status adapter overriding the ownership and idempotency hooks from fixed data.
 */
final class HookedStatusAdapter extends AbstractStatusAdapter
{
    /** @var list<array{orderId: string, platformStatusCode: string}> */
    public array $applied = [];

    /**
     * @param string[] $ownedOrders
     * @param array<string, string> $currentStatuses
     */
    public function __construct(private readonly array $ownedOrders, private array $currentStatuses, ?LoggerInterface $logger = null)
    {
        parent::__construct([], [], ['ACCEPTED' => 'accepted', 'REJECTED' => 'rejected'], '', $logger);
    }

    protected function acceptsOrder(string $orderId): bool
    {
        return in_array($orderId, $this->ownedOrders, true);
    }

    protected function isStatusAlreadyApplied(string $orderId, string $platformStatusCode): bool
    {
        return ($this->currentStatuses[$orderId] ?? null) === $platformStatusCode;
    }

    protected function applyStatus(string $orderId, string $platformStatusCode, string $comfinoStatus): void
    {
        $this->applied[] = ['orderId' => $orderId, 'platformStatusCode' => $platformStatusCode];
        $this->currentStatuses[$orderId] = $platformStatusCode;
    }
}
