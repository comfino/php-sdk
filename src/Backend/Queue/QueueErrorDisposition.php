<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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

    /** Permanent failure (4xx validation / auth): retrying cannot help - drop and log. */
    case DropPermanent;

    /** Not really a failure (e.g. 404/409 on a cancel = order already gone): treat as delivered. */
    case TreatAsSuccess;
}
