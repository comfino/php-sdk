<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

use Comfino\Enum\LoanTypeInterface;

/**
 * Validates a ComfinoPay Order DTO before submission to the ComfinoPay API.
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
    /** The submitted loan type is not among the types offered for this cart. */
    public const LOAN_TYPE_NOT_ALLOWED = 'loanSelection.type.notAllowed';
    /** The submitted loan term is outside the hard bounds or the type's configured term limits. */
    public const LOAN_TERM_OUT_OF_RANGE = 'loanSelection.term.outOfRange';

    /** Hard bounds on any loan term in months, applied whatever the configured limits say. */
    public const MIN_LOAN_TERM = 1;
    public const MAX_LOAN_TERM = 120;

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

    /**
     * Validates the loan type and term the shopper submitted, which arrive from the browser and are untrusted.
     *
     * @param LoanTypeInterface $loanType Submitted loan type
     * @param int $loanTerm Submitted term in months
     * @param array<int, LoanTypeInterface|string> $allowedTypes Types offered for this cart, e.g. from
     *                                                           {@see \Comfino\Backend\Settings\SettingsManager::resolveAllowedProductTypes()};
     *                                                           an empty list allows nothing
     * @param array<int, array{type: string, minTerm?: int, maxTerm?: int, terms?: int[]}>|null $termLimits Per-type term limits in
     *                                                               the normalized allowed-products-config shape, or null for none
     *
     * @return string[] Failure keys ({@see self::LOAN_TYPE_NOT_ALLOWED}, {@see self::LOAN_TERM_OUT_OF_RANGE}); empty when valid
     */
    public function validateLoanSelection(LoanTypeInterface $loanType, int $loanTerm, array $allowedTypes, ?array $termLimits = null): array
    {
        $errors = [];
        $type = $loanType->getValue();
        $allowedValues = array_map(
            static fn (LoanTypeInterface|string $allowedType): string => is_string($allowedType) ? $allowedType : $allowedType->getValue(),
            $allowedTypes
        );

        if (!in_array($type, $allowedValues, true)) {
            $errors[] = self::LOAN_TYPE_NOT_ALLOWED;
        }

        if ($loanTerm < self::MIN_LOAN_TERM || $loanTerm > self::MAX_LOAN_TERM || !self::termWithinLimits($type, $loanTerm, $termLimits ?? [])) {
            $errors[] = self::LOAN_TERM_OUT_OF_RANGE;
        }

        return $errors;
    }

    /**
     * @param array<int, array{type: string, minTerm?: int, maxTerm?: int, terms?: int[]}> $termLimits
     */
    private static function termWithinLimits(string $type, int $loanTerm, array $termLimits): bool
    {
        foreach ($termLimits as $limit) {
            if ($limit['type'] !== $type) {
                continue;
            }

            if (isset($limit['terms']) && !in_array($loanTerm, array_map('intval', $limit['terms']), true)) {
                return false;
            }

            if ((isset($limit['minTerm']) && $loanTerm < $limit['minTerm']) || (isset($limit['maxTerm']) && $loanTerm > $limit['maxTerm'])) {
                return false;
            }
        }

        return true;
    }
}
