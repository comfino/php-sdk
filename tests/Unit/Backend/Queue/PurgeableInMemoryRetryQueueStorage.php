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

use Comfino\Backend\Queue\PurgeableRetryQueueStorageInterface;
use Comfino\Backend\Queue\QueuedRequest;
use Comfino\Backend\Queue\RetryQueueStorageInterface;

/**
 * {@see InMemoryRetryQueueStorage} plus bulk deletion.
 */
final class PurgeableInMemoryRetryQueueStorage implements RetryQueueStorageInterface, PurgeableRetryQueueStorageInterface
{
    public function __construct(public readonly InMemoryRetryQueueStorage $inner = new InMemoryRetryQueueStorage())
    {
    }

    public function purge(?int $createdBefore = null, ?string $tenantKey = null, ?string $operationType = null): int
    {
        $purged = 0;

        foreach ($this->inner->peekBatch(PHP_INT_MAX, $tenantKey) as $request) {
            if (($createdBefore === null || $request->createdAt < $createdBefore) && ($operationType === null || $request->operationType === $operationType)) {
                $this->inner->remove($request);

                $purged++;
            }
        }

        return $purged;
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
        $this->inner->remove($request);
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
