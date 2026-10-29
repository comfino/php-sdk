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
 * Optional capability of a {@see RetryQueueStorageInterface}: bulk deletion.
 *
 * Queue rows can hold error-report payloads with request data until they drain. Implement this so the host can bound
 * their age ({@see OutboundRequestQueueProcessor}'s $maxAgeSeconds), purge the queue on uninstall, and erase one
 * tenant's rows on a data-subject request. A separate interface, so existing storages keep compiling.
 */
interface PurgeableRetryQueueStorageInterface
{
    /**
     * Deletes queued requests matching every given filter.
     *
     * @param int|null $createdBefore Delete only requests enqueued before this Unix timestamp; null = any age
     * @param string|null $tenantKey Delete only this tenant's requests; null = every tenant
     * @param string|null $operationType Delete only this operation type; null = every type
     *
     * @return int Number of requests deleted
     */
    public function purge(?int $createdBefore = null, ?string $tenantKey = null, ?string $operationType = null): int;
}
