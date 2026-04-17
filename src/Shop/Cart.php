<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Shop
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop;

use Comfino\Shop\Order\Cart\CartItemInterface;

/**
 * Represents a shopping cart with various attributes and cart items.
 */
class Cart
{
    /** @var int[]|null  */
    private ?array $cartCategoryIds = null;

    /**
     * @param int $totalValue Total cart value (including delivery cost and taxes)
     * @param int|null $totalNetValue Total cart net value
     * @param int|null $totalTaxValue Total cart tax value
     * @param int $deliveryCost Delivery cost
     * @param int|null $deliveryNetCost Delivery net cost
     * @param int|null $deliveryTaxRate Delivery tax rate
     * @param int|null $deliveryTaxValue Delivery tax value
     * @param CartItemInterface[] $cartItems Cart items (products)
     */
    public function __construct(
        private readonly int $totalValue,
        private readonly ?int $totalNetValue,
        private readonly ?int $totalTaxValue,
        private readonly int $deliveryCost,
        private readonly ?int $deliveryNetCost,
        private readonly ?int $deliveryTaxRate,
        private readonly ?int $deliveryTaxValue,
        private readonly array $cartItems
    ) {
    }

    /**
     * Returns the total value of the cart, including delivery cost and taxes.
     *
     * @return int Total cart value
     */
    public function getTotalValue(): int
    {
        return $this->totalValue;
    }

    /**
     * Returns the total net value of the cart.
     *
     * @return int|null Total cart net value
     */
    public function getTotalNetValue(): ?int
    {
        return $this->totalNetValue;
    }

    /**
     * Returns the total tax value of the cart.
     *
     * @return int|null Total cart tax value
     */
    public function getTotalTaxValue(): ?int
    {
        return $this->totalTaxValue;
    }

    /**
     * Returns the delivery cost of the cart.
     *
     * @return int Delivery cost
     */
    public function getDeliveryCost(): int
    {
        return $this->deliveryCost;
    }

    /**
     * Returns the net delivery cost of the cart.
     *
     * @return int|null Net delivery cost
     */
    public function getDeliveryNetCost(): ?int
    {
        return $this->deliveryNetCost;
    }

    /**
     * Returns the tax rate of the delivery cost of the cart.
     *
     * @return int|null Delivery tax rate
     */
    public function getDeliveryTaxRate(): ?int
    {
        return $this->deliveryTaxRate;
    }

    /**
     * Returns the tax value of the delivery cost of the cart.
     *
     * @return int|null Delivery tax value
     */
    public function getDeliveryTaxValue(): ?int
    {
        return $this->deliveryTaxValue;
    }

    /**
     * Returns the array of cart items (products).
     *
     * @return CartItemInterface[] Array of cart items (products)
     */
    public function getCartItems(): array
    {
        return $this->cartItems;
    }

    /**
     * Returns the number of items in the cart.
     *
     * @return int Number of items
     */
    public function getItemsCount(): int
    {
        return count($this->cartItems);
    }

    /**
     * Returns the total quantity of items in the cart.
     *
     * @return int Total quantity of items
     */
    public function getTotalItemsCount(): int
    {
        return array_reduce(
            $this->cartItems,
            static fn (int $carry, CartItemInterface $cartItem): int => $carry + $cartItem->getQuantity(),
            0
        );
    }

    /**
     * Returns the array of cart category IDs extracted from the cart items (products).
     *
     * @return int[] Array of cart category IDs
     */
    public function getCartCategoryIds(): array
    {
        if ($this->cartCategoryIds !== null) {
            return $this->cartCategoryIds;
        }

        $categoryIds = [];

        foreach ($this->cartItems as $cartItem) {
            if (($productCategoryIds = $cartItem->getProduct()->getCategoryIds()) !== null) {
                $categoryIds[] = $productCategoryIds;
            }
        }

        return ($this->cartCategoryIds = array_unique(array_merge([], ...$categoryIds), SORT_NUMERIC));
    }

    /**
     * Returns the cart as an array for frontend/template use (e.g., widget and paywall initialization scripts).
     *
     * This method is intended for plugin consumers that need to pass cart data to the frontend layer (e.g., Comfino web
     * frontend SDK). It includes product-level categoryIds, which are required for frontend category filtering, and
     * preserves null values by default so that JavaScript can distinguish missing fields.
     *
     * Do NOT confuse this with CartTrait::getCartAsArray() from the php-api-client library, which serializes a
     * CartInterface to the Comfino API wire format (strips nulls, adds discount/correction items when cart item totals
     * diverge from the order total, and omits categoryIds).
     *
     * @param bool $withNulls Whether to include null values in the output array (default: true)
     *
     * @return array<string, mixed> Cart data array for frontend template rendering
     */
    public function getAsArray(bool $withNulls = true): array
    {
        $cart = [
            'totalAmount' => $this->totalValue,
            'deliveryCost' => $this->deliveryCost,
            'deliveryNetCost' => $this->deliveryNetCost,
            'deliveryCostVatRate' => $this->deliveryTaxRate,
            'deliveryCostVatAmount' => $this->deliveryTaxValue,
            'products' => array_map(
                static function (CartItemInterface $cartItem) use ($withNulls): array {
                    $product = [
                        'name' => $cartItem->getProduct()->getName(),
                        'quantity' => $cartItem->getQuantity(),
                        'price' => $cartItem->getProduct()->getPrice(),
                        'netPrice' => $cartItem->getProduct()->getNetPrice(),
                        'vatRate' => $cartItem->getProduct()->getTaxRate(),
                        'vatAmount' => $cartItem->getProduct()->getTaxValue(),
                        'externalId' => $cartItem->getProduct()->getId(),
                        'category' => $cartItem->getProduct()->getCategory(),
                        'ean' => $cartItem->getProduct()->getEan(),
                        'photoUrl' => $cartItem->getProduct()->getPhotoUrl(),
                        'categoryIds' => $cartItem->getProduct()->getCategoryIds(),
                    ];

                    return $withNulls ? $product : array_filter(
                        $product,
                        static fn ($productFieldValue): bool => $productFieldValue !== null
                    );
                },
                $this->cartItems
            ),
        ];

        return $withNulls ? $cart : array_filter($cart, static fn ($cartFieldValue): bool => $cartFieldValue !== null);
    }
}
