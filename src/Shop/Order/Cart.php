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

use Comfino\Shop\Order\Cart\CartItemInterface;

/**
 * Represents a shopping cart entity for the Comfino API.
 */
class Cart implements CartInterface
{
    /**
     * @param CartItemInterface[] $items Shop cart items
     * @param int $totalAmount Total amount of the shop cart
     * @param int|null $deliveryCost Delivery cost of the shop cart
     * @param int|null $deliveryNetCost Delivery net cost of the shop cart
     * @param int|null $deliveryCostTaxRate Delivery cost tax rate of the shop cart
     * @param int|null $deliveryCostTaxValue Delivery cost tax value of the shop cart
     * @param string|null $category Category of the shop cart
     */
    public function __construct(
        private readonly array $items,
        private readonly int $totalAmount,
        private readonly ?int $deliveryCost = null,
        private readonly ?int $deliveryNetCost = null,
        private readonly ?int $deliveryCostTaxRate = null,
        private readonly ?int $deliveryCostTaxValue = null,
        private readonly ?string $category = null
    ) {
    }

    /** @inheritDoc */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @inheritDoc */
    public function getTotalAmount(): int
    {
        return $this->totalAmount;
    }

    /** @inheritDoc */
    public function getDeliveryCost(): ?int
    {
        return $this->deliveryCost;
    }

    /** @inheritDoc */
    public function getDeliveryNetCost(): ?int
    {
        return $this->deliveryNetCost;
    }

    /** @inheritDoc */
    public function getDeliveryCostTaxRate(): ?int
    {
        return $this->deliveryCostTaxRate;
    }

    /** @inheritDoc */
    public function getDeliveryCostTaxValue(): ?int
    {
        return $this->deliveryCostTaxValue;
    }

    /** @inheritDoc */
    public function getCategory(): ?string
    {
        return $this->category;
    }
}
