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
 * Storage double that deliberately ignores the due-at gate, to prove the queue stays correct with a non-compliant
 * adapter underneath it.
 */
final class GateIgnoringRetryQueueStorage implements RetryQueueStorageInterface
{
    /** @var array<int, QueuedRequest> */
    private array $items = [];

    private int $nextId = 1;

    /** @inheritDoc */
    public function enqueue(QueuedRequest $request): void
    {
        $this->seed($request);
    }

    /** @inheritDoc */
    public function peekBatch(int $limit, ?string $tenantKey = null, ?int $dueAt = null): array
    {
        // $dueAt deliberately ignored.
        $matching = array_filter($this->items, static fn (QueuedRequest $r): bool => $tenantKey === null || $r->tenantKey === $tenantKey);

        return array_slice(array_values($matching), 0, max(0, $limit));
    }

    /** @inheritDoc */
    public function update(QueuedRequest $request): void
    {
        if ($request->id !== null) {
            $this->items[(int) $request->id] = $request;
        }
    }

    /** @inheritDoc */
    public function remove(QueuedRequest $request): void
    {
        if ($request->id !== null) {
            unset($this->items[(int) $request->id]);
        }
    }

    /** @inheritDoc */
    public function count(?string $tenantKey = null): int
    {
        return count($this->peekBatch(PHP_INT_MAX, $tenantKey));
    }

    /** @inheritDoc */
    public function pendingTenantKeys(?int $dueAt = null): array
    {
        $tenantKeys = [];

        foreach ($this->items as $request) {
            if (!in_array($request->tenantKey, $tenantKeys, true)) {
                $tenantKeys[] = $request->tenantKey;
            }
        }

        return $tenantKeys;
    }

    /**
     * Test helper: inject a request directly.
     */
    public function seed(QueuedRequest $request): QueuedRequest
    {
        $id = $this->nextId++;
        $stored = $request->withId($id);

        $this->items[$id] = $stored;

        return $stored;
    }
}
