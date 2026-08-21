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

/**
 * Validates a Comfino Order DTO before submission to the Comfino API.
 *
 * Generic, platform-agnostic pre-validation shared by every plugin's checkout: customer contact data, delivery address,
 * and cart totals. Returns stable failure keys (not sentences) so each plugin can map them to translated,
 * customer-facing messages in its own translation layer.
 */
final class OrderValidator
{
    /** Customer e-mail is empty or not a valid address. */
    public const CUSTOMER_EMAIL_INVALID = 'customer.email.invalid';
    /** Customer phone number is missing. */
    public const CUSTOMER_PHONE_REQUIRED = 'customer.phone.required';
    /** Customer first name is missing. */
    public const CUSTOMER_FIRST_NAME_REQUIRED = 'customer.firstName.required';
    /** Customer last name is missing. */
    public const CUSTOMER_LAST_NAME_REQUIRED = 'customer.lastName.required';
    /** Delivery address is missing entirely. */
    public const ADDRESS_REQUIRED = 'address.required';
    /** Delivery address city is missing. */
    public const ADDRESS_CITY_REQUIRED = 'address.city.required';
    /** Delivery address postal code is missing. */
    public const ADDRESS_POSTAL_CODE_REQUIRED = 'address.postalCode.required';
    /** Cart has no items. */
    public const CART_EMPTY = 'cart.empty';
    /** Cart total amount is not greater than zero. */
    public const CART_TOTAL_AMOUNT_NON_POSITIVE = 'cart.totalAmount.nonPositive';

    /**
     * Validates the given order.
     *
     * @param OrderInterface $order Order DTO to validate
     *
     * @return string[] List of failure keys (empty array = valid). Keys are the class constants above.
     */
    public function validate(OrderInterface $order): array
    {
        $errors = [];
        $customer = $order->getCustomer();

        // 1. Customer e-mail
        $email = $customer->getEmail();

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = self::CUSTOMER_EMAIL_INVALID;
        }

        // 2. Phone number
        if (empty($customer->getPhoneNumber())) {
            $errors[] = self::CUSTOMER_PHONE_REQUIRED;
        }

        // 3. Customer names
        if (empty(trim($customer->getFirstName()))) {
            $errors[] = self::CUSTOMER_FIRST_NAME_REQUIRED;
        }

        if (empty(trim($customer->getLastName()))) {
            $errors[] = self::CUSTOMER_LAST_NAME_REQUIRED;
        }

        // 4. Delivery address
        $address = $customer->getAddress();

        if ($address === null) {
            $errors[] = self::ADDRESS_REQUIRED;
        } else {
            if (empty(trim($address->getCity() ?? ''))) {
                $errors[] = self::ADDRESS_CITY_REQUIRED;
            }

            if (empty(trim($address->getPostalCode() ?? ''))) {
                $errors[] = self::ADDRESS_POSTAL_CODE_REQUIRED;
            }
        }

        // 5. Cart items
        if (empty($order->getCart()->getItems())) {
            $errors[] = self::CART_EMPTY;
        }

        // 6. Total amount
        if ($order->getCart()->getTotalAmount() <= 0) {
            $errors[] = self::CART_TOTAL_AMOUNT_NON_POSITIVE;
        }

        return $errors;
    }
}
