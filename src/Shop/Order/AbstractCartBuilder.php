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

use Comfino\Shop\Cart;
use Comfino\Shop\Order\Cart\CartItemInterface;

/**
 * Base cart builder that provides platform-agnostic Cart DTO assembly and utility helpers.
 *
 * Subclasses implement the abstract extraction methods to map platform-specific cart/product representations onto the
 * Comfino Cart DTO.
 */
abstract class AbstractCartBuilder implements CartBuilderInterface
{
    /**
     * {@inheritDoc}
     *
     * Assembles a Cart DTO by calling the platform-specific extraction methods, then computing aggregate net/tax
     * totals from individual cart items. Subclasses must implement the abstract extraction methods;
     * extractDeliveryTaxRate and extractDeliveryTaxValue have concrete default implementations derived from the gross
     * and net delivery costs and may be overridden when platform data allows a more precise result.
     */
    public function buildCart(mixed $platformCart, int $priceModifier = 0): Cart
    {
        // Extract all cart data from the platform representation.
        $cartItems = $this->extractCartItems($platformCart);
        $deliveryCostGross = $this->extractDeliveryCostGross($platformCart);
        $deliveryCostNet = $this->extractDeliveryCostNet($platformCart);
        $deliveryTaxRate = $this->extractDeliveryTaxRate($platformCart);
        $deliveryTaxValue = $this->extractDeliveryTaxValue($platformCart);
        $cartTotalGross = $this->extractCartTotalGross($platformCart) + $priceModifier;

        // Compute aggregate net and tax values from individual cart item prices.
        $totalNetValue = 0;
        $totalTaxValue = 0;
        $hasTaxData = false;

        foreach ($cartItems as $cartItem) {
            // Accumulate aggregate net and tax totals; null means the platform did not supply tax data for the item.
            $netPrice = $cartItem->getProduct()->getNetPrice();
            $taxValue = $cartItem->getProduct()->getTaxValue();

            if ($netPrice !== null) {
                $totalNetValue += $netPrice * $cartItem->getQuantity();
                $hasTaxData = true;
            }

            if ($taxValue !== null) {
                $totalTaxValue += $taxValue * $cartItem->getQuantity();
            }
        }

        if (!$hasTaxData || $totalNetValue > PHP_INT_MAX) {
            $totalNetValue = null;
        }

        if (!$hasTaxData || $totalTaxValue > PHP_INT_MAX) {
            $totalTaxValue = null;
        }

        if ($totalNetValue === 0) {
            $totalNetValue = null;
        }

        if ($totalTaxValue === 0) {
            $totalTaxValue = null;
        }

        return new Cart(
            $cartTotalGross,
            $totalNetValue,
            $totalTaxValue,
            $deliveryCostGross,
            $deliveryCostNet,
            $deliveryTaxRate,
            $deliveryTaxValue,
            $cartItems
        );
    }

    /**
     * {@inheritDoc}
     *
     * Delegates to {@see extractSingleProductData()} which subclasses implement to build a single-item Cart from a
     * platform product representation.
     */
    public function buildCartFromSingleProduct(mixed $platformProduct): Cart
    {
        return $this->extractSingleProductData($platformProduct);
    }

    /**
     * Extracts cart items (products) from a platform cart.
     *
     * @param mixed $platformCart Platform-specific cart representation
     *
     * @return CartItemInterface[] Array of CartItem instances
     */
    abstract protected function extractCartItems(mixed $platformCart): array;

    /**
     * Extracts the gross delivery cost from a platform cart (in grosze).
     *
     * @param mixed $platformCart Platform-specific cart representation
     *
     * @return int Delivery cost in grosze
     */
    abstract protected function extractDeliveryCostGross(mixed $platformCart): int;

    /**
     * Extracts the net delivery cost from a platform cart (in grosze).
     *
     * Must return null only when delivery is free (deliveryCostGross = 0). When delivery has no VAT (0% VAT or
     * VAT-free), must return the same value as extractDeliveryCostGross so that the tax value correctly computes to 0.
     *
     * @param mixed $platformCart Platform-specific cart representation
     *
     * @return int|null Net delivery cost in grosze, or null when there is no delivery cost at all
     */
    abstract protected function extractDeliveryCostNet(mixed $platformCart): ?int;

    /**
     * Returns the delivery VAT rate derived from the gross and net delivery costs.
     *
     * Returns null when deliveryCostNet is null (free shipping) or when net equals gross (no VAT — either VAT-free or
     * 0% VAT; platforms that can distinguish the two should override this method and return 0 for explicit 0% VAT).
     *
     * @param mixed $platformCart Platform-specific cart representation
     *
     * @return int|null Tax rate as integer percentage (e.g. 23 for 23%), or null when no tax or VAT-free
     */
    protected function extractDeliveryTaxRate(mixed $platformCart): ?int
    {
        $deliveryCostGross = $this->extractDeliveryCostGross($platformCart);
        $deliveryCostNet = $this->extractDeliveryCostNet($platformCart);

        if ($deliveryCostNet === null || $deliveryCostNet >= $deliveryCostGross) {
            return null;
        }

        return $deliveryCostNet > 0
            ? (int) round(($deliveryCostGross - $deliveryCostNet) / $deliveryCostNet * 100)
            : (int) round(($deliveryCostGross - $deliveryCostNet) / $deliveryCostGross * 100);
    }

    /**
     * Returns the delivery VAT amount (gross minus net delivery cost) in grosze.
     *
     * Returns null when deliveryCostNet is null (no delivery cost at all), 0 when delivery carries no VAT.
     *
     * @param mixed $platformCart Platform-specific cart representation
     *
     * @return int|null Tax value in grosze, or null when no delivery cost is present
     */
    protected function extractDeliveryTaxValue(mixed $platformCart): ?int
    {
        $deliveryCostGross = $this->extractDeliveryCostGross($platformCart);
        $deliveryCostNet = $this->extractDeliveryCostNet($platformCart);

        return $deliveryCostNet !== null ? $deliveryCostGross - $deliveryCostNet : null;
    }

    /**
     * Extracts the total gross cart value from a platform cart (in grosze).
     *
     * The price modifier is applied on top of this value in {@see buildCart()}.
     *
     * @param mixed $platformCart Platform-specific cart representation
     *
     * @return int Total gross cart value in grosze
     */
    abstract protected function extractCartTotalGross(mixed $platformCart): int;

    /**
     * Builds a single-item Cart DTO directly from a platform product representation.
     *
     * @param mixed $platformProduct Platform-specific product representation
     *
     * @return Cart Single-item Cart DTO
     */
    abstract protected function extractSingleProductData(mixed $platformProduct): Cart;
}
