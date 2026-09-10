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

use Comfino\Shop\Order\OrderValidator;
use Comfino\Shop\Order\Cart\CartItem;
use Comfino\Shop\Order\Cart\Product;
use Comfino\Shop\Order\CustomerInterface;
use Comfino\Shop\Order\Customer\AddressInterface;
use Comfino\Shop\Order\OrderInterface;
use Comfino\Shop\Order\CartInterface;
use PHPUnit\Framework\TestCase;

final class OrderValidatorTest extends TestCase
{
    private OrderValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OrderValidator();
    }

    public function testValidOrderReturnsNoErrors(): void
    {
        $order = $this->buildOrder();

        self::assertSame([], $this->validator->validate($order));
    }

    /**
     * @dataProvider invalidFieldProvider
     *
     * @param array<string, mixed> $overrides
     */
    public function testSingleFieldFailureProducesExactKey(array $overrides, string $expectedKey): void
    {
        $order = $this->buildOrder($overrides);

        self::assertSame([$expectedKey], $this->validator->validate($order));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFieldProvider(): array
    {
        return [
            'empty email' => [['email' => ''], OrderValidator::CUSTOMER_EMAIL_INVALID],
            'malformed email' => [['email' => 'not-an-email'], OrderValidator::CUSTOMER_EMAIL_INVALID],
            'empty phone' => [['phone' => ''], OrderValidator::CUSTOMER_PHONE_REQUIRED],
            'blank first name' => [['firstName' => '   '], OrderValidator::CUSTOMER_FIRST_NAME_REQUIRED],
            'blank last name' => [['lastName' => ''], OrderValidator::CUSTOMER_LAST_NAME_REQUIRED],
            'null address' => [['address' => null], OrderValidator::ADDRESS_REQUIRED],
            'blank city' => [['city' => ' '], OrderValidator::ADDRESS_CITY_REQUIRED],
            'blank postal code' => [['postalCode' => ''], OrderValidator::ADDRESS_POSTAL_CODE_REQUIRED],
            'empty cart' => [['cartItems' => []], OrderValidator::CART_EMPTY],
            'non-positive total' => [['totalAmount' => 0], OrderValidator::CART_TOTAL_AMOUNT_NON_POSITIVE],
        ];
    }

    public function testMultipleFailuresAccumulateInOrder(): void
    {
        $order = $this->buildOrder([
            'email' => '',
            'phone' => '',
            'firstName' => '',
            'lastName' => '',
            'city' => '',
            'postalCode' => '',
            'cartItems' => [],
            'totalAmount' => 0,
        ]);

        self::assertSame(
            [
                OrderValidator::CUSTOMER_EMAIL_INVALID,
                OrderValidator::CUSTOMER_PHONE_REQUIRED,
                OrderValidator::CUSTOMER_FIRST_NAME_REQUIRED,
                OrderValidator::CUSTOMER_LAST_NAME_REQUIRED,
                OrderValidator::ADDRESS_CITY_REQUIRED,
                OrderValidator::ADDRESS_POSTAL_CODE_REQUIRED,
                OrderValidator::CART_EMPTY,
                OrderValidator::CART_TOTAL_AMOUNT_NON_POSITIVE,
            ],
            $this->validator->validate($order)
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildOrder(array $overrides = []): OrderInterface
    {
        $defaults = [
            'email' => 'customer@example.com',
            'phone' => '+48123456789',
            'firstName' => 'Jan',
            'lastName' => 'Kowalski',
            'city' => 'Warszawa',
            'postalCode' => '00-001',
            'address' => 'set',
            'cartItems' => [new CartItem(new Product('P', 1000), 1)],
            'totalAmount' => 1000,
        ];

        $data = array_merge($defaults, $overrides);

        $address = null;

        if ($data['address'] !== null) {
            $address = $this->createMock(AddressInterface::class);
            $address->method('getCity')->willReturn($data['city']);
            $address->method('getPostalCode')->willReturn($data['postalCode']);
        }

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($data['email']);
        $customer->method('getPhoneNumber')->willReturn($data['phone']);
        $customer->method('getFirstName')->willReturn($data['firstName']);
        $customer->method('getLastName')->willReturn($data['lastName']);
        $customer->method('getAddress')->willReturn($address);

        $cart = $this->createMock(CartInterface::class);
        $cart->method('getItems')->willReturn($data['cartItems']);
        $cart->method('getTotalAmount')->willReturn($data['totalAmount']);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getCart')->willReturn($cart);

        return $order;
    }
}
