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
 * Outcome of {@see OutboundRequestQueue::submit()}.
 */
enum SubmitResult: string
{
    /** Delivered on the fast path (or treated as already-delivered); nothing was queued. */
    case SentImmediately = 'sent_immediately';

    /** Fast path failed transiently; the request was persisted for later resend. */
    case Queued = 'queued';

    /** Fast path failed permanently; the request was logged and dropped (retrying cannot help). */
    case DroppedPermanent = 'dropped_permanent';
}
