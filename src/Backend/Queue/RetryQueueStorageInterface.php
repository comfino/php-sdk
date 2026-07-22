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
 * Durable, FIFO-ordered persistence for the outbound request queue.
 *
 * This is the only mandatory platform-specific piece of the queue subsystem: each e-commerce platform implements it
 * over its native durable store (Magento DB table, WooCommerce/PrestaShop SQL table, etc.). The SDK owns all
 * orchestration on top of it.
 *
 * Ordering contract: {@see peekBatch()} MUST return requests oldest-first by insertion order (e.g. ascending
 * auto-increment id). Concurrency contract: implementations SHOULD claim/lock the rows they return (transaction +
 * row lock, or a short-lived lock column) so concurrent drains do not double-send.
 */
interface RetryQueueStorageInterface
{
    /**
     * Appends a request, assigning it a FIFO sequence identifier (preserved in {@see QueuedRequest::$id}).
     *
     * Implementations SHOULD skip insertion when a pending entry with the same {@see QueuedRequest::dedupKey()}
     * already exists, to avoid piling up redundant operations.
     */
    public function enqueue(QueuedRequest $request): void;

    /**
     * Returns up to $limit pending requests, oldest first.
     *
     * @return QueuedRequest[]
     */
    public function peekBatch(int $limit): array;

    /**
     * Persists updated attempt count / last error for a previously enqueued request.
     */
    public function update(QueuedRequest $request): void;

    /**
     * Removes a request permanently (after successful delivery, or after it is dead-lettered).
     */
    public function remove(QueuedRequest $request): void;

    /**
     * Returns the number of pending requests.
     */
    public function count(): int;
}
