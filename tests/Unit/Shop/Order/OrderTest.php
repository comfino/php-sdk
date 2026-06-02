<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Shop\Order;

use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\CustomerInterface;
use Comfino\Shop\Order\LoanParametersInterface;
use Comfino\Shop\Order\Order;
use Comfino\Shop\Order\SellerInterface;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private LoanParametersInterface $loanParams;
    private CartInterface $cart;
    private CustomerInterface $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loanParams = $this->createMock(LoanParametersInterface::class);
        $this->cart = $this->createMock(CartInterface::class);
        $this->customer = $this->createMock(CustomerInterface::class);
    }

    /**
     * @param array<int, mixed>|null $allowedProductsConfig
     */
    private function makeOrder(
        string $id = 'ORD-1',
        string $returnUrl = 'https://example.com/return',
        ?string $notifyUrl = null,
        ?string $accountNumber = null,
        ?string $transferTitle = null,
        ?SellerInterface $seller = null,
        ?array $allowedProductsConfig = null
    ): Order {
        return new Order(
            $id,
            $returnUrl,
            $this->loanParams,
            $this->cart,
            $this->customer,
            $notifyUrl,
            $seller,
            $accountNumber,
            $transferTitle,
            $allowedProductsConfig
        );
    }

    public function testGetIdReturnsConstructedId(): void
    {
        $this->assertSame('ORD-42', $this->makeOrder('ORD-42')->getId());
    }

    public function testGetReturnUrlStripsTagsAndTrims(): void
    {
        $order = $this->makeOrder(returnUrl: '  <b>https://shop.com/return</b>  ');

        $this->assertSame('https://shop.com/return', $order->getReturnUrl());
    }

    public function testGetNotifyUrlStripsTagsAndTrims(): void
    {
        $order = $this->makeOrder(notifyUrl: '  <b>https://shop.com/notify</b>  ');

        $this->assertSame('https://shop.com/notify', $order->getNotifyUrl());
    }

    public function testGetNotifyUrlReturnsNullWhenNotSet(): void
    {
        $this->assertNull($this->makeOrder()->getNotifyUrl());
    }

    public function testGetLoanParametersReturnsInjectedObject(): void
    {
        $this->assertSame($this->loanParams, $this->makeOrder()->getLoanParameters());
    }

    public function testGetCartReturnsInjectedObject(): void
    {
        $this->assertSame($this->cart, $this->makeOrder()->getCart());
    }

    public function testGetCustomerReturnsInjectedObject(): void
    {
        $this->assertSame($this->customer, $this->makeOrder()->getCustomer());
    }

    public function testGetSellerReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeOrder()->getSeller());
    }

    public function testGetSellerReturnsInjectedSeller(): void
    {
        $seller = $this->createMock(SellerInterface::class);

        $this->assertSame($seller, $this->makeOrder(seller: $seller)->getSeller());
    }

    public function testGetAccountNumberDecodesEntityAndStripsTagsAndTrims(): void
    {
        $order = $this->makeOrder(accountNumber: '  <b>PL&amp;12 3456</b>  ');

        $this->assertSame('PL&12 3456', $order->getAccountNumber());
    }

    public function testGetAccountNumberReturnsNullWhenNotSet(): void
    {
        $this->assertNull($this->makeOrder()->getAccountNumber());
    }

    public function testGetTransferTitleDecodesEntityAndStripsTagsAndTrims(): void
    {
        $order = $this->makeOrder(transferTitle: '  <i>Zam&oacute;wienie #1</i>  ');

        $this->assertSame('Zamówienie #1', $order->getTransferTitle());
    }

    public function testGetTransferTitleReturnsNullWhenNotSet(): void
    {
        $this->assertNull($this->makeOrder()->getTransferTitle());
    }

    public function testGetAllowedProductsConfigReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeOrder()->getAllowedProductsConfig());
    }

    public function testGetAllowedProductsConfigReturnsInjectedArray(): void
    {
        $config = [['type' => 'INSTALLMENTS_ZERO_PERCENT', 'minTerm' => 3, 'maxTerm' => 24]];
        $order = $this->makeOrder(allowedProductsConfig: $config);

        $this->assertSame($config, $order->getAllowedProductsConfig());
    }
}
