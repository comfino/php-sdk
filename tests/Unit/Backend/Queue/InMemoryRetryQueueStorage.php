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
use Comfino\Backend\Queue\RetryQueueStorageInterface;

/**
 * Array-backed FIFO storage double mirroring the contract a real (DB-backed) adapter must honor: auto-increment id
 * preserves insertion order; enqueue dedups on dedupKey().
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

    public function peekBatch(int $limit): array
    {
        return array_slice(array_values($this->items), 0, max(0, $limit));
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

    public function count(): int
    {
        return count($this->items);
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
