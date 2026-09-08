<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Queue;

/**
 * How the queue should treat a thrown delivery error.
 */
enum QueueErrorDisposition
{
    /** Transient failure (timeout / network / 5xx / 429): keep the request and retry later. */
    case Retry;

    /** Permanent failure (4xx validation): retrying cannot help - drop and log. */
    case DropPermanent;

    /** Not really a failure (e.g., 404/409 on a cancel = order already gone): treat as delivered. */
    case TreatAsSuccess;

    /**
     * The tenant's credentials are refused (401/403): keep the request, stop draining *this tenant's* partition, and
     * raise an operational alert.
     *
     * This used to be classified as {@see DropPermanent}, on the reasoning that a 4xx cannot be fixed by retrying. For
     * an authorization failure that reasoning is wrong twice over. It is not a property of the request - the payload is
     * fine, the key is wrong - so retrying after the key is fixed succeeds. And it is not one request's problem: every
     * request for that merchant will fail the same way, so dropping them one by one silently discards a merchant's
     * entire payment-relevant outbound traffic while the queue reports itself healthy. What a 401 actually means is
     * "this tenant is misconfigured", which is a configuration event for a human, and the right response is to hold
     * that tenant's work and say so - while every other tenant keeps draining.
     */
    case PauseTenant;
}
