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
 *
 * Multi-tenant contract: a store shared by several merchants MUST persist {@see QueuedRequest::$tenantKey} and honor
 * it in {@see peekBatch()}, {@see count()} and {@see pendingTenantKeys()}. Without that the queue cannot be fair — one
 * merchant with broken credentials would hold every other merchant's work behind them — and the dedup key cannot tell
 * two merchants' identical operations apart. A single-shop plugin can ignore the tenant parameters entirely; they are
 * null in every call it will ever see.
 *
 * Scheduling contract: implementations SHOULD honor the `$dueAt` gate of {@see peekBatch()}, filtering out requests
 * whose {@see QueuedRequest::$availableAt} lies in the future. An implementation that ignores it stays correct — the
 * queue re-checks and skips the item — but wastes batch slots on work it cannot do yet, which is exactly what the
 * per-item backoff exists to avoid.
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
     * @param int $limit Maximum number of requests to return
     * @param string|null $tenantKey When given, return only this tenant's requests; when null, return requests from
     *                               every tenant
     * @param int|null $dueAt When given, return only requests whose `availableAt` is at or before this Unix timestamp
     *
     * @return QueuedRequest[]
     */
    public function peekBatch(int $limit, ?string $tenantKey = null, ?int $dueAt = null): array;

    /**
     * Persists updated attempt count / last error / next-attempt time for a previously enqueued request.
     */
    public function update(QueuedRequest $request): void;

    /**
     * Removes a request permanently (after successful delivery, or after it is dead-lettered).
     */
    public function remove(QueuedRequest $request): void;

    /**
     * Returns the number of pending requests.
     *
     * @param string|null $tenantKey When given, count only this tenant's requests; when null, count all of them
     */
    public function count(?string $tenantKey = null): int;

    /**
     * Returns the distinct tenant keys that currently have pending requests, oldest-waiting tenant first.
     *
     * This is what makes a fair drain possible: the queue cycles the tenants this returns instead of draining the
     * table front-to-back, so one merchant's backlog cannot monopolize a batch. The ordering matters less than the
     * completeness — but returning the tenant whose oldest request has waited longest first keeps starvation bounded.
     *
     * A single-tenant store returns `[null]` when it has pending work and `[]` when it does not.
     *
     * @param int|null $dueAt When given, consider only requests due at or before this Unix timestamp
     *
     * @return list<string|null> Distinct tenant keys with pending work
     */
    public function pendingTenantKeys(?int $dueAt = null): array;
}
