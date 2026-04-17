<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

use Comfino\Enum\OrderStatus;
use Comfino\Enum\OrderStatusInterface;

/**
 * Singleton manager for updating order statuses via a shop adapter.
 */
class StatusManager
{
    /** @var OrderStatusInterface[] Statuses that should not trigger status update in the shop. */
    public const DEFAULT_IGNORED_STATUSES = [
        OrderStatus::WAITING_FOR_FILLING,
        OrderStatus::WAITING_FOR_CONFIRMATION,
        OrderStatus::WAITING_FOR_PAYMENT,
        OrderStatus::PAID,
    ];

    /** @var OrderStatusInterface[] Statuses that are not allowed to be set in the shop. */
    public const DEFAULT_FORBIDDEN_STATUSES = [OrderStatus::RESIGN];

    /**
     * @var self|null Singleton instance of the StatusManager.
     */
    private static ?self $instance = null;

    /**
     * Returns the singleton instance, creating it with the given adapter on the first call.
     *
     * @param StatusAdapterInterface $orderStatusAdapter Adapter for updating order statuses in the shop provided by
     *                                                   the shop platform
     *
     * @return self Singleton instance of the StatusManager
     */
    public static function getInstance(StatusAdapterInterface $orderStatusAdapter): self
    {
        if (self::$instance === null) {
            self::$instance = new self($orderStatusAdapter);
        }

        return self::$instance;
    }

    /**
     * Resets the singleton instance (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function __construct(private readonly StatusAdapterInterface $orderStatusAdapter)
    {
    }

    /**
     * Delegates order status update to the shop adapter.
     *
     * @param string $externalId Shop internal order ID (external ID sent in the order creation request)
     * @param string $status New order status (one of the OrderStatusInterface::* constants)
     */
    public function setOrderStatus(string $externalId, string $status): void
    {
        $this->orderStatusAdapter->setStatus($externalId, $status);
    }
}
