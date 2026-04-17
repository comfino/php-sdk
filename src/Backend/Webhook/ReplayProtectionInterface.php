<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Backend\Webhook
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Webhook;

/**
 * Replay protection interface for webhook requests.
 */
interface ReplayProtectionInterface
{
    /**
     * Checks if the webhook request is a duplicate.
     *
     * @param string $signature The signature of the webhook request
     *
     * @return bool True if the request is a duplicate, false otherwise
     */
    public function isDuplicate(string $signature): bool;

    /**
     * Marks the webhook request as processed.
     *
     * @param string $signature The signature of the webhook request
     */
    public function markProcessed(string $signature): void;
}
