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

use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\RetryQueueStorageInterface;

/**
 * Array-backed FIFO storage double mirroring the contract a real (DB-backed) adapter must honor: auto-increment id
 * preserves insertion order; enqueue dedups on dedupKey(); the tenant filter and the due-at gate are both honored, so
 * tests exercise the same fairness and pacing behavior a compliant SQL adapter would produce.
 */
final class InMemoryRetryQueueStorage implements RetryQueueStorageInterface
{
    /** @var array<int, QueuedRequest> id => request, insertion-ordered */
    private array $items = [];

    private int $nextId = 1;

    public function enqueue(QueuedRequest $request): void
    {
        foreach ($this->items as $existing) {
            if ($existing->dedupKey() === $request->dedupKey()) {
                return; // dedup: identical pending operation already queued.
            }
        }

        $id = $this->nextId++;

        $this->items[$id] = $request->withId($id);
    }

    public function peekBatch(int $limit, ?string $tenantKey = null, ?int $dueAt = null): array
    {
        $matching = array_filter(
            $this->items,
            static fn (QueuedRequest $request): bool =>
                ($tenantKey === null || $request->tenantKey === $tenantKey) &&
                ($dueAt === null || $request->isDue($dueAt))
        );

        return array_slice(array_values($matching), 0, max(0, $limit));
    }

    public function update(QueuedRequest $request): void
    {
        if ($request->id !== null && isset($this->items[$request->id])) {
            $this->items[$request->id] = $request;
        }
    }

    public function remove(QueuedRequest $request): void
    {
        if ($request->id !== null) {
            unset($this->items[$request->id]);
        }
    }

    public function count(?string $tenantKey = null): int
    {
        if ($tenantKey === null) {
            return count($this->items);
        }

        return count(array_filter($this->items, static fn (QueuedRequest $r): bool => $r->tenantKey === $tenantKey));
    }

    public function pendingTenantKeys(?int $dueAt = null): array
    {
        $tenantKeys = [];

        foreach ($this->items as $request) {
            if ($dueAt !== null && !$request->isDue($dueAt)) {
                continue;
            }

            if (!in_array($request->tenantKey, $tenantKeys, true)) {
                $tenantKeys[] = $request->tenantKey;
            }
        }

        return $tenantKeys;
    }

    /**
     * Test helper: inject a request directly (e.g., one whose operation type has no registered handler).
     */
    public function seed(QueuedRequest $request): QueuedRequest
    {
        $id = $this->nextId++;
        $stored = $request->withId($id);

        $this->items[$id] = $stored;

        return $stored;
    }
}
