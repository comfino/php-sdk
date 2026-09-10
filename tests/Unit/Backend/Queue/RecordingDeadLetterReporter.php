<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Queue;

use Comfino\Backend\Queue\DeadLetterReporterInterface;
use Comfino\Backend\Queue\QueuedRequest;
use Throwable;

/**
 * Records every dead-letter report for assertions.
 */
final class RecordingDeadLetterReporter implements DeadLetterReporterInterface
{
    /** @var array<int, array{request: QueuedRequest, error: Throwable}> */
    public array $reports = [];

    public function report(QueuedRequest $request, Throwable $error): void
    {
        $this->reports[] = ['request' => $request, 'error' => $error];
    }

    public function count(): int
    {
        return count($this->reports);
    }
}
