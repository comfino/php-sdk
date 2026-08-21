<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Backend\Queue
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Backend\Queue;

use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\TenantPauseReporterInterface;
use Throwable;

/**
 * Records the operational alerts raised behind a paused partition.
 */
final class RecordingPauseReporter implements TenantPauseReporterInterface
{
    /** @var array<int, array{tenantKey: string|null, request: QueuedRequest, error: Throwable, reason: string}> */
    public array $reports = [];

    /** @inheritDoc */
    public function reportPaused(
        ?string $tenantKey,
        QueuedRequest $request,
        Throwable $error,
        string $reason
    ): void {
        $this->reports[] = [
            'tenantKey' => $tenantKey,
            'request' => $request,
            'error' => $error,
            'reason' => $reason,
        ];
    }
}
