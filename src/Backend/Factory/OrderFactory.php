<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Factory
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Factory;

use Comfino\Enum\LoanTypeInterface;
use Comfino\Shop\Order\Cart;
use Comfino\Shop\Order\Cart\CartItemInterface;
use Comfino\Shop\Order\CustomerInterface;
use Comfino\Shop\Order\LoanParameters;
use Comfino\Shop\Order\Order;

/**
 * Factory for creating Comfino order objects.
 */
final class OrderFactory
{
    /**
     * Creates a Comfino order object.
     *
     * @param string $orderId Order ID (shop order ID sent as external ID)
     * @param int $orderTotal Total order amount including delivery cost and taxes (in cents)
     * @param int $deliveryCost Delivery cost (in cents, gross value)
     * @param int $loanTerm Loan term in months
     * @param LoanTypeInterface $loanType Loan type (selected financial product type)
     * @param CartItemInterface[] $cartItems List of cart items
     * @param CustomerInterface $customer Customer object
     * @param string $returnUrl URL to redirect the customer to after successful order completion
     * @param string $notificationUrl URL to notify about order status changes (webhook endpoint provided by
     *                                shop/e-commerce platform)
     * @param LoanTypeInterface[]|null $allowedProductTypes List of allowed financial product types
     * @param int|null $deliveryNetCost Delivery net cost (in cents, net value)
     * @param int|null $deliveryCostTaxRate Delivery cost tax rate (in percent)
     * @param int|null $deliveryCostTaxValue Delivery cost tax value (in cents)
     * @param string|null $category Category of the order (e.g., "electronics", "clothing")
     *
     * @return Order Comfino order object (loan application) 100% ready to be sent to Comfino API
     */
    public function createOrder(
        string $orderId,
        int $orderTotal,
        int $deliveryCost,
        int $loanTerm,
        LoanTypeInterface $loanType,
        array $cartItems,
        CustomerInterface $customer,
        string $returnUrl,
        string $notificationUrl,
        ?array $allowedProductTypes = null,
        ?int $deliveryNetCost = null,
        ?int $deliveryCostTaxRate = null,
        ?int $deliveryCostTaxValue = null,
        ?string $category = null
    ): Order {
        return new Order(
            $orderId,
            $returnUrl,
            new LoanParameters(
                $orderTotal,
                $loanTerm,
                $loanType,
                $allowedProductTypes
            ),
            new Cart(
                $cartItems,
                $orderTotal,
                $deliveryCost,
                $deliveryNetCost,
                $deliveryCostTaxRate,
                $deliveryCostTaxValue,
                $category
            ),
            $customer,
            $notificationUrl
        );
    }
}
