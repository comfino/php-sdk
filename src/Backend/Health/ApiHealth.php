<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Health
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Health;

/**
 * API call health of one tenant, as recorded by {@see ApiHealthRecorder}. Timestamps are Unix seconds.
 */
final class ApiHealth
{
    public function __construct(
        public readonly ?int $lastSuccessAt = null,
        public readonly ?int $lastFailureAt = null,
        public readonly int $consecutiveFailures = 0,
        public readonly ?string $lastFailureClass = null
    ) {
    }

    /**
     * @return array{last_success_at: ?int, last_failure_at: ?int, consecutive_failures: int, last_failure_class: ?string}
     */
    public function toArray(): array
    {
        return [
            'last_success_at' => $this->lastSuccessAt,
            'last_failure_at' => $this->lastFailureAt,
            'consecutive_failures' => $this->consecutiveFailures,
            'last_failure_class' => $this->lastFailureClass,
        ];
    }
}
