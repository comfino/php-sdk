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
 * Performs the actual API call for a queued operation type.
 *
 * Handlers are intentionally thin: the platform decides which API client (and therefore which timeout profile) backs
 * the handler, so the queue core stays agnostic of HTTP concerns. A handler MUST throw on failure - the queue
 * classifies the thrown exception to decide whether to retry, drop, or treat it as success.
 */
interface RetryableOperationHandlerInterface
{
    /**
     * Executes the operation described by the given payload.
     *
     * @param array<string, scalar> $payload
     *
     * @throws \Throwable On any delivery failure (classified by the queue).
     */
    public function execute(array $payload): void;
}
