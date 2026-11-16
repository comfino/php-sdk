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
use RuntimeException;

/**
 * Storage double whose remove() fails, as a database hiccup right after a successful delivery would.
 */
final class RemoveFailingQueueStorage implements RetryQueueStorageInterface
{
    public function __construct(private readonly InMemoryRetryQueueStorage $inner)
    {
    }

    public function enqueue(QueuedRequest $request): void
    {
        $this->inner->enqueue($request);
    }

    public function peekBatch(int $limit, ?string $tenantKey = null, ?int $dueAt = null): array
    {
        return $this->inner->peekBatch($limit, $tenantKey, $dueAt);
    }

    public function update(QueuedRequest $request): void
    {
        $this->inner->update($request);
    }

    public function remove(QueuedRequest $request): void
    {
        throw new RuntimeException('Storage unavailable.');
    }

    public function count(?string $tenantKey = null): int
    {
        return $this->inner->count($tenantKey);
    }

    public function pendingTenantKeys(?int $dueAt = null): array
    {
        return $this->inner->pendingTenantKeys($dueAt);
    }
}
