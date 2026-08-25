<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Frontend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Frontend;

use Comfino\Shop\Cart;

/**
 * Serializes a Cart DTO into the flat array the paywall iframe consumes (COMFINO_CART_UPDATE).
 *
 * This is the generic, platform-agnostic cart shape shared by every plugin's checkout: a product list plus the
 * cart-level delivery fields. All numeric delivery/product fields default to 0 (and category to '') when null — the
 * paywall expects concrete numbers rather than nulls, unlike {@see Cart::getAsArray()} which preserves nulls and
 * carries extra frontend-only fields (externalId, ean, photoUrl, categoryIds).
 */
final class PaywallCartSerializer
{
    /**
     * Serializes a Cart into the flat array format expected by the paywall iframe. All numeric delivery fields default
     * to 0 when null (no shipping / no tax on shipping).
     *
     * @param Cart $cart Cart to serialize
     *
     * @return array<string, mixed>|null Null only when the cart has no items
     */
    public static function serialize(Cart $cart): ?array
    {
        $products = [];

        foreach ($cart->getCartItems() as $cartItem) {
            $product = $cartItem->getProduct();

            $products[] = [
                'name' => $product->getName(),
                'quantity' => $cartItem->getQuantity(),
                'price' => $product->getPrice(),
                'netPrice' => $product->getNetPrice() ?? $product->getPrice(),
                'vatRate' => $product->getTaxRate() ?? 0,
                'vatAmount' => $product->getTaxValue() ?? 0,
                'category' => $product->getCategory() ?? '',
            ];
        }

        if (empty($products)) {
            return null;
        }

        return [
            'totalAmount' => $cart->getTotalValue(),
            'deliveryCost' => $cart->getDeliveryCost(),
            'deliveryNetCost' => $cart->getDeliveryNetCost() ?? 0,
            'deliveryCostVatRate' => $cart->getDeliveryTaxRate() ?? 0,
            'deliveryCostVatAmount' => $cart->getDeliveryTaxValue() ?? 0,
            'products' => $products,
        ];
    }
}
