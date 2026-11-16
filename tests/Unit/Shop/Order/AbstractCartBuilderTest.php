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

use Comfino\Shop\Cart;
use Comfino\Shop\Order\AbstractCartBuilder;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\CartItemInterface;
use Comfino\Shop\Order\Cart\Product;
use PHPUnit\Framework\TestCase;

final class AbstractCartBuilderTest extends TestCase
{
    /**
     * Builds a concrete cart builder whose extraction hooks return the supplied fixture data.
     *
     * @param CartItemInterface[] $items
     */
    private function makeBuilder(
        array $items,
        int $deliveryCostGross,
        ?int $deliveryCostNet,
        int $cartTotalGross,
        ?Cart $singleProductCart = null
    ): AbstractCartBuilder {
        $args = [$items, $deliveryCostGross, $deliveryCostNet, $cartTotalGross, $singleProductCart];

        return new class (...$args) extends AbstractCartBuilder {
            /**
             * @param CartItemInterface[] $items
             */
            public function __construct(
                private readonly array $items,
                private readonly int $deliveryCostGross,
                private readonly ?int $deliveryCostNet,
                private readonly int $cartTotalGross,
                private readonly ?Cart $singleProductCart
            ) {
            }

            protected function extractCartItems(mixed $platformCart): array
            {
                return $this->items;
            }

            protected function extractDeliveryCostGross(mixed $platformCart): int
            {
                return $this->deliveryCostGross;
            }

            protected function extractDeliveryCostNet(mixed $platformCart): ?int
            {
                return $this->deliveryCostNet;
            }

            protected function extractCartTotalGross(mixed $platformCart): int
            {
                return $this->cartTotalGross;
            }

            protected function extractSingleProductData(mixed $platformProduct): Cart
            {
                return $this->singleProductCart ?? new Cart(0, null, null, 0, null, null, null, []);
            }
        };
    }

    /**
     * @param CartItemInterface[] $items
     */
    private function builderWithItems(array $items, int $cartTotalGross = 3075): AbstractCartBuilder
    {
        return $this->makeBuilder($items, 1230, 1000, $cartTotalGross);
    }

    public function testBuildCartAggregatesNetAndTaxValuesAcrossItems(): void
    {
        $items = [
            new CartItem(new Product('A', 1230, netPrice: 1000, taxRate: 23, taxValue: 230), 2),
            new CartItem(new Product('B', 615, netPrice: 500, taxRate: 23, taxValue: 115), 1),
        ];

        $cart = $this->builderWithItems($items)->buildCart(new \stdClass());

        // 1000 * 2 + 500 * 1
        $this->assertSame(2500, $cart->getTotalNetValue());
        // 230 * 2 + 115 * 1
        $this->assertSame(575, $cart->getTotalTaxValue());
        $this->assertSame(3075, $cart->getTotalValue());
        $this->assertCount(2, $cart->getCartItems());
    }

    public function testBuildCartAppliesPriceModifierToGrossTotal(): void
    {
        $items = [new CartItem(new Product('A', 1230, netPrice: 1000, taxValue: 230), 1)];

        $cart = $this->builderWithItems($items, 3075)->buildCart(new \stdClass(), 500);

        $this->assertSame(3575, $cart->getTotalValue());
    }

    public function testBuildCartReturnsNullTotalsWhenNoTaxData(): void
    {
        $items = [
            new CartItem(new Product('A', 1230), 2),
            new CartItem(new Product('B', 500), 1),
        ];

        $cart = $this->builderWithItems($items)->buildCart(new \stdClass());

        $this->assertNull($cart->getTotalNetValue());
        $this->assertNull($cart->getTotalTaxValue());
    }

    public function testBuildCartComputesDeliveryTaxRateAndValueFromGrossAndNet(): void
    {
        $items = [new CartItem(new Product('A', 1230, netPrice: 1000, taxValue: 230), 1)];

        $cart = $this->makeBuilder($items, 1230, 1000, 3075)->buildCart(new \stdClass());

        $this->assertSame(1230, $cart->getDeliveryCost());
        $this->assertSame(1000, $cart->getDeliveryNetCost());
        $this->assertSame(23, $cart->getDeliveryTaxRate());
        $this->assertSame(230, $cart->getDeliveryTaxValue());
    }

    public function testBuildCartReturnsNullDeliveryTaxWhenDeliveryIsFree(): void
    {
        $items = [new CartItem(new Product('A', 1230, netPrice: 1000, taxValue: 230), 1)];

        $cart = $this->makeBuilder($items, 0, null, 3075)->buildCart(new \stdClass());

        $this->assertNull($cart->getDeliveryNetCost());
        $this->assertNull($cart->getDeliveryTaxRate());
        $this->assertNull($cart->getDeliveryTaxValue());
    }

    public function testBuildCartReturnsNullDeliveryTaxRateWhenNetEqualsGross(): void
    {
        $items = [new CartItem(new Product('A', 1000, netPrice: 1000, taxValue: 0), 1)];

        $cart = $this->makeBuilder($items, 1000, 1000, 2000)->buildCart(new \stdClass());

        $this->assertNull($cart->getDeliveryTaxRate());
        $this->assertSame(0, $cart->getDeliveryTaxValue());
    }

    public function testBuildCartFromSingleProductDelegatesToExtraction(): void
    {
        $singleCart = new Cart(999, 800, 199, 0, null, null, null, [
            new CartItem(new Product('Single', 999, netPrice: 800, taxValue: 199), 1),
        ]);

        $builder = $this->makeBuilder([], 0, null, 0, $singleCart);

        $this->assertSame($singleCart, $builder->buildCartFromSingleProduct(new \stdClass()));
    }
}
