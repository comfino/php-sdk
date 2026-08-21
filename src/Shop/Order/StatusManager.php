<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

use Comfino\Enum\OrderStatus;
use Comfino\Enum\OrderStatusInterface;

/**
 * Manager for updating order statuses via a shop adapter, with one instance per tenant scope.
 *
 * This used to be a process-wide singleton, and it was the most dangerous one in the SDK: the first tenant to call
 * {@see getInstance()} owned the adapter, so every later tenant's status notification was routed into the first
 * tenant's adapter and applied to the first tenant's orders. Hosts serving several merchants from one process had to
 * call `reset()` before every webhook to defuse it - a workaround that only works as long as nobody forgets. Instances
 * are keyed by scope, following the {@see ConfigurationManager} pattern, so passing the tenant is what selects the
 * adapter and the hack is unnecessary. The default empty scope preserves the original behavior for single-shop plugins.
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

    /** @var array<string, self> One instance per tenant scope, keyed by the scope string. */
    private static array $instances = [];

    /**
     * Retrieves a per-scope instance, creating it with the given adapter on the first call for that scope.
     *
     * @param StatusAdapterInterface $orderStatusAdapter Adapter for updating order statuses in the shop provided by
     *                                                   the shop platform
     * @param string $scope Tenant scope discriminator (empty string = global/default scope)
     *
     * @return self The instance bound to $scope
     */
    public static function getInstance(StatusAdapterInterface $orderStatusAdapter, string $scope = ''): self
    {
        return self::$instances[$scope] ??= new self($orderStatusAdapter, $scope);
    }

    /**
     * Drops cached instances, releasing the adapters they hold.
     *
     * @param string|null $scope When given, only the instance bound to this scope is dropped; when null (default), all
     *                           cached instances (every scope) are dropped.
     */
    public static function reset(?string $scope = null): void
    {
        if ($scope === null) {
            self::$instances = [];
        } else {
            unset(self::$instances[$scope]);
        }
    }

    /**
     * Private constructor to enforce the per-scope instance pattern.
     *
     * @param StatusAdapterInterface $orderStatusAdapter Adapter for updating order statuses in the shop
     * @param string $scope Tenant scope this instance belongs to
     */
    private function __construct(
        private readonly StatusAdapterInterface $orderStatusAdapter,
        private readonly string $scope = ''
    ) {
    }

    /**
     * Returns the tenant scope this instance is bound to.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Returns the status-application context for this instance's scope, which platform event hooks query to suppress
     * reflexive outbound calls while an API-initiated status change is being written.
     */
    public function applicationContext(): StatusApplicationContext
    {
        return StatusApplicationContext::forScope($this->scope);
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
