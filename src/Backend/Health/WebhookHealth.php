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
 * Webhook verification health of one tenant, as recorded by {@see WebhookHealthRecorder}.
 */
final class WebhookHealth
{
    /**
     * @param int $recentMismatches Signature mismatches within the recorder's window (forged or misconfigured senders)
     * @param int $windowSeconds Length of that window
     * @param int|null $lastVerifiedAt Unix time of the last verified delivery
     * @param int|null $lastMismatchAt Unix time of the last mismatch
     */
    public function __construct(
        public readonly int $recentMismatches = 0,
        public readonly int $windowSeconds = 0,
        public readonly ?int $lastVerifiedAt = null,
        public readonly ?int $lastMismatchAt = null
    ) {
    }

    /**
     * @return array{recent_mismatches: int, window_seconds: int, last_verified_at: ?int, last_mismatch_at: ?int}
     */
    public function toArray(): array
    {
        return [
            'recent_mismatches' => $this->recentMismatches,
            'window_seconds' => $this->windowSeconds,
            'last_verified_at' => $this->lastVerifiedAt,
            'last_mismatch_at' => $this->lastMismatchAt,
        ];
    }
}
