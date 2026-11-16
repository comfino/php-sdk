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

/**
 * Cleans up a customer-entered promotional code before it is attached to an order.
 *
 * The API accepts at most 64 characters and rejects an empty string with a 400, which would fail the whole order
 * creation. A code that cannot be valid is therefore dropped (null) instead of being sent.
 */
final class PromoCode
{
    public const MAX_LENGTH = 64;

    /**
     * Name of the request field a checkout page script submits the code under, next to `comfino_loan_type` and
     * `comfino_loan_term`.
     */
    public const REQUEST_FIELD = 'comfino_promo_code';

    /**
     * @param mixed $value Raw value from a request, cart state or session; anything but a string is ignored
     */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $code = html_entity_decode(strip_tags($value));

        // Unicode spaces (e.g. a decoded &nbsp;) are trimmed too; invalid UTF-8 falls back to a plain trim().
        $code = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $code) ?? trim($code);

        if ($code === '' || mb_strlen($code) > self::MAX_LENGTH) {
            return null;
        }

        return $code;
    }
}
