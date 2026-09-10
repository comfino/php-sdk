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

use Throwable;

/**
 * Notified when a request is dropped permanently or gives up after exhausting its retry attempts.
 *
 * Platforms typically wire this to their error tracker (e.g., ComfinoPay's ErrorLogger) so operators learn about
 * cancellations that could never be delivered.
 */
interface DeadLetterReporterInterface
{
    public function report(QueuedRequest $request, Throwable $error): void;
}
